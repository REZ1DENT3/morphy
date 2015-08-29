<?php

namespace Deimos\Morphy\Morphier;


use Deimos\Morphy\AncodesResolver\AncodesResolverInterface;
use Deimos\Morphy\AnnotDecoder\AnnotDecoderInterface;
use Deimos\Morphy\Gram\Info\InfoInterface;
use Deimos\Morphy\Gram\Tab\TabInterface;

class Helper
{

    protected
        $graminfo,
        $annot_decoder,
        $char_size,
        $ends,
        $gramtab,
        $ancodes_resolver,
        $gramtab_consts_included = false,
        $resolve_pos;

    function __construct(
        InfoInterface $graminfo,
        TabInterface $gramtab,
        AncodesResolverInterface $ancodesResolver,
        $resolvePartOfSpeech
    )
    {
        $this->graminfo = $graminfo;
        $this->gramtab = $gramtab;
        $this->resolve_pos = (bool)$resolvePartOfSpeech;
        $this->ancodes_resolver = $ancodesResolver;

        $this->char_size = $graminfo->getCharSize();

        $this->ends = $graminfo->getEnds();
    }

    function setAnnotDecoder(AnnotDecoderInterface $annotDecoder)
    {
        $this->annot_decoder = $annotDecoder;
    }

    // getters
    function getEndOfString()
    {
        return $this->ends;
    }

    function getCharSize()
    {
        return $this->char_size;
    }

    function hasAnnotDecoder()
    {
        return isset($this->annot_decoder);
    }

    function getAnnotDecoder()
    {
        return $this->annot_decoder;
    }

    function getAncodesResolver()
    {
        return $this->ancodes_resolver;
    }

    function getGramInfo()
    {
        return $this->graminfo;
    }

    function getGramTab()
    {
        return $this->gramtab;
    }

    function isResolvePartOfSpeech()
    {
        return $this->resolve_pos;
    }

    // other
    function resolvePartOfSpeech($posId)
    {
        return $this->gramtab->resolvePartOfSpeechId($posId);
    }

    function getGrammems($ancodeId)
    {
        return $this->gramtab->getGrammems($ancodeId);
    }

    function getGrammemsAndPartOfSpeech($ancodeId)
    {
        return array(
            $this->gramtab->getPartOfSpeech($ancodeId),
            $this->gramtab->getGrammems($ancodeId)
        );
    }

    function extractPartOfSpeech($annot)
    {
        if ($this->resolve_pos) {
            return $this->resolvePartOfSpeech($annot['pos_id']);
        }
        else {
            return $annot['pos_id'];
        }
    }

    protected function includeGramTabConsts()
    {
        if ($this->isResolvePartOfSpeech()) {
            $this->gramtab->includeConsts();
        }

        $this->gramtab_consts_included = true;
    }

    // getters
    function getWordDescriptor($word, $annots)
    {
        if (!$this->gramtab_consts_included) {
            $this->includeGramTabConsts();
        }

        return new phpMorphy_WordDescriptor_Collection($word, $annots, $this);
    }

    protected function getBaseAndPrefix($word, $cplen, $plen, $flen)
    {
        if ($flen) {
            $base = $GLOBALS['__phpmorphy_substr']($word, $cplen + $plen, -$flen);
        }
        else {
            if ($cplen || $plen) {
                $base = $GLOBALS['__phpmorphy_substr']($word, $cplen + $plen);
            }
            else {
                $base = $word;
            }
        }

        $prefix = $cplen ? $GLOBALS['__phpmorphy_substr']($word, 0, $cplen) : '';

        return array($base, $prefix);
    }

    function getPartOfSpeech($word, $annots)
    {
        if (false === $annots) {
            return false;
        }

        $result = array();

        foreach ($this->decodeAnnot($annots, false) as $annot) {
            $result[$this->extractPartOfSpeech($annot)] = 1;
        }

        return array_keys($result);
    }

    function getBaseForm($word, $annots)
    {
        if (false === $annots) {
            return false;
        }

        $annots = $this->decodeAnnot($annots, true);

        return $this->composeBaseForms($word, $annots);
    }

    function getPseudoRoot($word, $annots)
    {
        if (false === $annots) {
            return false;
        }

        $annots = $this->decodeAnnot($annots, false);

        $result = array();

        foreach ($annots as $annot) {
            list($base) = $this->getBaseAndPrefix(
                $word,
                $annot['cplen'],
                $annot['plen'],
                $annot['flen']
            );

            $result[$base] = 1;
        }

        return array_keys($result);
    }

    function getAllForms($word, $annots)
    {
        if (false === $annots) {
            return false;
        }

        $annots = $this->decodeAnnot($annots, false);

        return $this->composeForms($word, $annots);
    }

    function castFormByGramInfo($word, $annots, $partOfSpeech, $grammems, $returnWords = false, $callback = null)
    {
        if (false === $annots) {
            return false;
        }

        if (isset($callback) && !is_callable($callback)) {
            throw new phpMorphy_Exception("Invalid callback given");
        }

        $result = array();
        $grammems = (array)$grammems;
        $partOfSpeech = isset($partOfSpeech) ? (string)$partOfSpeech : null;

        foreach ($this->decodeAnnot($annots, false) as $annot) {
            $all_ancodes = $this->graminfo->readAncodes($annot);
            $flexias = $this->graminfo->readFlexiaData($annot);
            $common_ancode = $annot['common_ancode'];
            $common_grammems = isset($common_ancode) ? $this->gramtab->getGrammems($common_ancode) : array();

            list($base, $prefix) = $this->getBaseAndPrefix(
                $word,
                $annot['cplen'],
                $annot['plen'],
                $annot['flen']
            );

            // i use strange $form_no handling for perfomance issue (no function call overhead)
            $i = 0;
            $form_no = 0;
            foreach ($all_ancodes as $form_ancodes) {
                foreach ($form_ancodes as $ancode) {
                    $form_pos = $this->gramtab->getPartOfSpeech($ancode);
                    $form_grammems = array_merge($this->gramtab->getGrammems($ancode), $common_grammems);
                    $form = $prefix . $flexias[$i] . $base . $flexias[$i + 1];

                    if (isset($callback)) {
                        if (!call_user_func($callback, $form, $form_pos, $form_grammems, $form_no)) {
                            $form_no++;
                            continue;
                        }
                    }
                    else {
                        if (isset($partOfSpeech) && $form_pos !== $partOfSpeech) {
                            $form_no++;
                            continue;
                        }

                        if (count(array_diff($grammems, $form_grammems)) > 0) {
                            $form_no++;
                            continue;
                        }
                    }

                    if ($returnWords) {
                        $result[$form] = 1;
                    }
                    else {
                        $result[] = array(
                            'form' => $form,
                            'form_no' => $form_no,
                            'pos' => $form_pos,
                            'grammems' => $form_grammems
                        );
                    }

                    $form_no++;
                }

                $i += 2;
            }
        }

        return $returnWords ? array_keys($result) : $result;
    }

    function getAncode($annots)
    {
        if (false === $annots) {
            return false;
        }

        $result = array();

        foreach ($this->decodeAnnot($annots, false) as $annot) {
            $all_ancodes = $this->graminfo->readAncodes($annot);

            $result[] = array(
                'common' => $this->ancodes_resolver->resolve($annot['common_ancode']),
                'all' => array_map(
                    array($this->ancodes_resolver, 'resolve'),
                    $all_ancodes[$annot['form_no']]
                )
            );
        }

        return $this->array_unique($result);
    }

    protected static function array_unique($array)
    {
        static $need_own;

        if (!isset($need_own)) {
            $need_own = -1 === version_compare(PHP_VERSION, '5.2.9');
        }

        if ($need_own) {
            $result = array();

            foreach (array_keys(array_unique(array_map('serialize', $array))) as $key) {
                $result[$key] = $array[$key];
            }

            return $result;
        }
        else {
            return array_unique($array, SORT_REGULAR);
        }
    }


    function getGrammarInfoMergeForms($annots)
    {
        if (false === $annots) {
            return false;
        }

        $result = array();

        foreach ($this->decodeAnnot($annots, false) as $annot) {
            $all_ancodes = $this->graminfo->readAncodes($annot);
            $common_ancode = $annot['common_ancode'];
            $grammems = isset($common_ancode) ? $this->gramtab->getGrammems($common_ancode) : array();

            $forms_count = 0;
            $form_no = $annot['form_no'];

            foreach ($all_ancodes[$form_no] as $ancode) {
                $grammems = array_merge($grammems, $this->gramtab->getGrammems($ancode));
                $forms_count++;
            }

            $grammems = array_unique($grammems);
            sort($grammems);

            $result[] = array(
                // part of speech identical across all joined forms
                'pos' => $this->gramtab->getPartOfSpeech($ancode),
                'grammems' => $grammems,
                'forms_count' => $forms_count,
                'form_no_low' => $form_no,
                'form_no_high' => $form_no + $forms_count,
            );
        }

        return $this->array_unique($result);
    }

    function getGrammarInfo($annots)
    {
        if (false === $annots) {
            return false;
        }

        $result = array();

        foreach ($this->decodeAnnot($annots, false) as $annot) {
            $all_ancodes = $this->graminfo->readAncodes($annot);
            $common_ancode = $annot['common_ancode'];
            $common_grammems = isset($common_ancode) ? $this->gramtab->getGrammems($common_ancode) : array();

            $info = array();

            $form_no = $annot['form_no'];
            foreach ($all_ancodes[$form_no] as $ancode) {
                $grammems = //array_unique(
                    array_merge($common_grammems, $this->gramtab->getGrammems($ancode));
                //);

                sort($grammems);

                $info_item = array(
                    'pos' => $this->gramtab->getPartOfSpeech($ancode),
                    'grammems' => $grammems,
                    'form_no' => $form_no,
                );


                $info[] = $info_item;
            }

            $unique_info = $this->array_unique($info);
            sort($unique_info);
            $result[] = $unique_info;
        }

        return $this->array_unique($result);
    }

    function getAllFormsWithResolvedAncodes($word, $annots, $resolveType = 'no_resolve')
    {
        if (false === $annots) {
            return false;
        }

        $annots = $this->decodeAnnot($annots, false);

        return $this->composeFormsWithResolvedAncodes($word, $annots);
    }

    function getAllFormsWithAncodes($word, $annots, &$foundFormNo = array())
    {
        if (false === $annots) {
            return false;
        }

        $annots = $this->decodeAnnot($annots, false);

        return $this->composeFormsWithAncodes($word, $annots, $foundFormNo);
    }

    function getAllAncodes($word, $annots)
    {
        if (false === $annots) {
            return false;
        }

        $result = array();

        foreach ($annots as $annot) {
            $result[] = $this->graminfo->readAncodes($annot);
        }

        return $result;
    }

    protected function composeBaseForms($word, $annots)
    {
        $result = array();

        foreach ($annots as $annot) {

            if ($annot['form_no'] > 0) {
                list($base, $prefix) = $this->getBaseAndPrefix(
                    $word,
                    $annot['cplen'],
                    $annot['plen'],
                    $annot['flen']
                );

                $result[$prefix . $annot['base_prefix'] . $base . $annot['base_suffix']] = 1;
            }
            else {
                $result[$word] = 1;
            }
        }

        return array_keys($result);
    }

    protected function composeForms($word, $annots)
    {
        $result = array();

        foreach ($annots as $annot) {
            list($base, $prefix) = $this->getBaseAndPrefix(
                $word,
                $annot['cplen'],
                $annot['plen'],
                $annot['flen']
            );

            // read flexia
            $flexias = $this->graminfo->readFlexiaData($annot);

            for ($i = 0, $c = count($flexias); $i < $c; $i += 2) {
                $result[$prefix . $flexias[$i] . $base . $flexias[$i + 1]] = 1;
            }
        }

        return array_keys($result);
    }

    protected function composeFormsWithResolvedAncodes($word, $annots)
    {
        $result = array();

        foreach ($annots as $annotIdx => $annot) {
            list($base, $prefix) = $this->getBaseAndPrefix(
                $word,
                $annot['cplen'],
                $annot['plen'],
                $annot['flen']
            );

            $words = array();
            $ancodes = array();
            $common_ancode = $annot['common_ancode'];

            // read flexia
            $flexias = $this->graminfo->readFlexiaData($annot);
            $all_ancodes = $this->graminfo->readAncodes($annot);

            for ($i = 0, $c = count($flexias); $i < $c; $i += 2) {
                $form = $prefix . $flexias[$i] . $base . $flexias[$i + 1];

                $current_ancodes = $all_ancodes[$i / 2];
                foreach ($current_ancodes as $ancode) {
                    $words[] = $form;
                    $ancodes[] = $this->ancodes_resolver->resolve($ancode);
                }
            }

            $result[] = array(
                'forms' => $words,
                'common' => $this->ancodes_resolver->resolve($common_ancode),
                'all' => $ancodes,
            );
        }

        return $result;
    }

    protected function composeFormsWithAncodes($word, $annots, &$foundFormNo)
    {
        $result = array();

        foreach ($annots as $annotIdx => $annot) {
            list($base, $prefix) = $this->getBaseAndPrefix(
                $word,
                $annot['cplen'],
                $annot['plen'],
                $annot['flen']
            );

            // read flexia
            $flexias = $this->graminfo->readFlexiaData($annot);
            $ancodes = $this->graminfo->readAncodes($annot);

            $found_form_no = $annot['form_no'];

            $foundFormNo = !is_array($foundFormNo) ? array() : $foundFormNo;

            for ($i = 0, $c = count($flexias); $i < $c; $i += 2) {
                $form_no = $i / 2;
                $word = $prefix . $flexias[$i] . $base . $flexias[$i + 1];

                if ($found_form_no == $form_no) {
                    $count = count($result);
                    $foundFormNo[$annotIdx]['low'] = $count;
                    $foundFormNo[$annotIdx]['high'] = $count + count($ancodes[$form_no]) - 1;
                }

                foreach ($ancodes[$form_no] as $ancode) {
                    $result[] = array($word, $ancode);
                }
            }
        }

        return $result;
    }

    function decodeAnnot($annotsRaw, $withBase)
    {
        if (is_array($annotsRaw)) {
            return $annotsRaw;
        }
        else {
            return $this->annot_decoder->decode($annotsRaw, $withBase);
        }
    }
}