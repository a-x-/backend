<?php
/**
 * @file Invntrm-Common-PHP / common.php
 * Created: 23.05.14 / 21:25
 */

namespace Invntrm;

define('ROOT', $_SERVER['DOCUMENT_ROOT'] . '/');
define('SRV', ROOT . '_ass/');

///**
// * @param $className
// */
//function __autoload($className)
//{
//    require_once(SRV . "$className.php");
//}

function getFNameStamp($fileMame, $isPathRewriteActive = false)
{
    return ($isPathRewriteActive ? preg_replace('!^.*/!', '', $fileMame) : $fileMame) . '?t' . filemtime(ROOT . preg_replace('!\.min!i', '', $fileMame));
}

/**
 *
 * Substitution variables into placeholders in the $template
 * @param string $template - Target text
 * @param array (mixed)[] $vars      - Set of variables
 * @return          string          - Specified result
 */
function specifyTemplate($template, $vars)
{
    return preg_replace_callback('/%([a-z_\-]+?)%/i',
        function ($matches) use ($vars) {
            return (isset($vars[$matches[1]])) ? $vars[$matches[1]] : '';
        },
        $template
    );
}

function parseMetaPage($str)
{
    $rawPage = (get_preg_match('!^(.*?)\r?\n\r?(?:\r?\n)+\s*(.*)$!s', $str));
    if (!count($rawPage)) {
        _d([1,[],$str]);
        return [[], $str];
    }
    list($meta, $body) = $rawPage;
    $metaLines = preg_split('!\s*\r?\n\s*!', $meta);
    foreach ($metaLines as &$metaLine) {
        $metaLine = get_preg_match('!^(.*?)\s*:\s*(.*)$!', $metaLine);
    }
    $meta = akv2okv($metaLines);
    _d([2,$meta, $body,]);
    return [$meta, $body,];
}

function buildPage($path, $params = [])
{
    if (!$path)
        header('Location: /404');
    $defaultPrefix = '/_views';
    list($templateWithMeta, $pageDir) = getFileContent($path, 'html', $defaultPrefix);
    _d([-1,$templateWithMeta, $pageDir]);
    list($meta, $template) = parseMetaPage($templateWithMeta);
    _d([$meta,$template]);
    $out = $template;
    $pageObject = get_require($path); // try execute template's logic
    $params = array_merge($params, $pageObject); // add page php script given object
    $params = array_merge($params, get_defined_constants()); // add constants
    $params = array_merge($params, $_SERVER); // add server constants
    $params = array_merge($params, $_REQUEST); // add server constants
    $paramMapping = (isset($pageObject['_PARAM_MAPPING_'])) ? $pageObject['_PARAM_MAPPING_'] : [];
    if (isset($pageObject['_STOP_'])) {
        $stopRef = $pageObject['_STOP_']; // ref to redirect page or null for 404
        header("Location: $stopRef");
    }
    //
    // Replace recursive call placeholders
    $out = preg_replace_callback('/@([a-z_\-\/]+?)@/i',
        function ($matches) use ($params, $pageDir, $defaultPrefix) {
            $match = $matches[1];
            if (!$pageDir || preg_match('!^/!', $match)) // if pageDir isn't set OR @placeholder@ start with /, than decide match absolute
                $pageDir = ROOT . $defaultPrefix;
            else
                $pageDir = "$pageDir/";
            buildPage($pageDir . $matches[1], $params);
        },
        $out
    );
    $out = specifyTemplateExtended($out, $params, $paramMapping);
    if (isset($meta['base'])) { // if base tpl is declared
        $params['content'] = $out;
        return buildPage($meta['base'], $params);
    } else
        return $out;
}


/**
 *
 * Substitution variables into placeholders in the $template
 * @param string $template - Target text
 * @param array (mixed)[] $vars      - Set of variables
 * @param array $paramMapping
 * @return          string          - Specified result
 */
function specifyTemplateExtended($template, $vars = [], $paramMapping = [])
{
    $out = $template;
    //
    // Replace simple variable placeholders
    $out = preg_replace_callback('/%([a-z_\-]+?)%/i',
        function ($matches) use ($vars, $paramMapping) {
            if (
                $paramMapping
                && array_key_exists(
                    $matches[1] /*needle var`s Name*/,
                    $paramMapping
                )
                && $vars[$matches[1]] /*needle var`s Value*/
                == $paramMapping[$matches[1]][0] /*replacement Value*/
            )
                return $paramMapping[$matches[1]][1];
            if (!isset($vars[$matches[1]])) {
                bugReport2("specifyTemplate()", " placeholder '$matches[1]' haven't value");
                return '';
            } else return $vars[$matches[1]];
        },
        $out
    );
    //
    // Replace parametrized variable placeholders
    $out = preg_replace_callback(
        '/%%([a-z_\-]+?)\[([a-z_\-]+?)\]%%/i',
        function ($matches) use ($vars, $paramMapping) {
            if (!isset($vars[$matches[1]]) || !isset($vars[$matches[1]][$matches[2]])) {
                bugReport2("specifyTemplate()", "placeholder '[ $matches[1] ][ $matches[2] ]' haven't value");
                return '';
            } else
                return $vars[$matches[1]][$matches[2]];
        }
        , $out
    );
    return $out;
}

/**
 * @param $fileName__filePath
 * @param $defaultExtension
 * @param $defaultPrefix
 * @return array|string
 */
function getFileContent($fileName__filePath, $defaultExtension, $defaultPrefix)
{
    $filePath = '';
    if (preg_match('!^\/[^\n]+$!', $fileName__filePath)) { // Load file if path present
        $filePath = $fileName__filePath;
        if (!preg_match('!' . $defaultPrefix . '!', $filePath)) {
            $filePath = ROOT . $defaultPrefix . $filePath;
        }
        //
        // Add extension by default if no set
        if (!preg_match('!\.!', preg_replace('!^.+/!m', '', $filePath))) {
            $filePath = preg_replace('!/$!', '', $filePath);
            $filePath .= '.' . ($defaultExtension ? $defaultExtension : 'html');
        }
        $template = file_get_contents($filePath);
    } else {
        $template = $fileName__filePath;
    }
    return [$template, dirname($filePath)];
}


/**
 * Node.js like require function.
 * @example $fs = require('fs');
 * @example fs.php: $exports = []; $exports['read'] = function (name){...}
 * @param $phpFileName
 * @param $prefix
 * @param bool $isStrict
 * @return array
 */
function get_require($phpFileName, $prefix = null, $isStrict = false)
{
    if ($prefix === null) $prefix = ROOT . '_views/';
    $phpFileName = $prefix . preg_replace('!/$!', '', $phpFileName) . '.php';
    if ($isStrict) {
        require($phpFileName);
    } else {
        @include($phpFileName);
    }
    if (isset($exports))
        return $exports;
    else
        return [];
}

function getDirList($path, $excludeMimes = array(), $isDebug = false)
{
    $out_arr = array();
    if (is_dir($path) && ($dir = opendir($path))) {
        // Сканируем директорию
        while (false !== ($file = readdir($dir))) {
            // Убираем лишние элементы
            if ($file != '.' && $file != '..' && !in_array($mime = getFileInfo($path . '/' . $file), $excludeMimes)) {
                $out_arr[] = $file . ($isDebug ? "($mime)" : '');
            }
        }
        // Закрываем директорию
        closedir($dir);
        return $out_arr;
    } else
        return false;
}

/**
 * @param $text
 */
function _d($text)
{
    file_put_contents(ROOT . '_logs/check.log', "\n" . date(DATE_RSS) . '>' . \Invntrm\varDumpRet($text), FILE_APPEND);
}

/**
 * @param $type
 * @param $text
 */
function bugReport2($type, $text)
{
    file_put_contents(ROOT . '_logs/error.log', "\n" . date(DATE_RSS) . '>' . $type . '>' . $text, FILE_APPEND);
}

function getFileInfo($filePath, $typeInfo = FILEINFO_MIME_TYPE)
{
    $fInfo = finfo_open();
    $fInfoResult = fInfo_file($fInfo, $filePath, $typeInfo);
    return $fInfoResult;
}


/**
 * Decode JSON file as associative array by its path
 * @param $path
 * @return mixed
 */
function json_decode_file($path)
{
    return json_decode(file_get_contents($path), true);
}

/**
 * Вычислить значение многомерного массива, ключ которого задан строкой key1.key2.key3. ... keyN
 * @param $path
 * @param $root
 * @return mixed|bool
 */
function evalDeepArrayPath($path, $root)
{
    $dirs = preg_split('/\./', $path);
    for ($i = 0, $l = count($dirs); $i < $l; ++$i) {
        $dir = $dirs[$i];
        if (isset($root[$dir])) $root = $root[$dir];
        else return false;
    }
    return $root;
}

/**
 * Специализировать маску (подставить одно из значений вместо указанного плейсхолдера)
 * @param $mask string
 * @param $placeholder string
 * @param $value string
 * @return string
 */
function specializeMask($mask, $placeholder, $value)
{
    return str_replace('%%' . $placeholder . '%%', $value, $mask);
}

/**
 * Return "String of some text" from some "sTrIng OF some TeXT".
 * First letter of none unicode text turn to uppercase,
 * another letters turn to lowercase
 * @param $string
 * @return string
 */
function uppercaseFirstLetter($string)
{
    return ucfirst(strtolower($string));
}

/**
 * Return "Строку некоторого текста", from some "сТрокУ НЕКОТОРОГО текста"
 * First letter of unicode text turn to uppercase, another letters turn to lowercase
 * @param $string
 * @return string
 */
function mb_uppercaseFirstLetter($string)
{
    list($first_str) = explode(' ', trim($string));
    return mb_convert_case($first_str, MB_CASE_TITLE, "utf-8") . mb_strtolower(strstr($string, ' '), "utf-8");
}


/**
 * function xml2array
 *
 * This function is part of the PHP manual.
 *
 * The PHP manual text and comments are covered by the Creative Commons
 * Attribution 3.0 License, copyright (c) the PHP Documentation Group
 *
 * @author  k dot antczak at livedata dot pl
 * @date    2011-04-22 06:08 UTC
 * @link    http://www.php.net/manual/en/ref.simplexml.php#103617
 * @license http://www.php.net/license/index.php#doc-lic
 * @license http://creativecommons.org/licenses/by/3.0/
 * @license CC-BY-3.0 <http://spdx.org/licenses/CC-BY-3.0>
 */
function xml2array($xmlObject, $out = array())
{
    foreach ((array)$xmlObject as $index => $node)
        $out[$index] = (is_object($node)) ? xml2array($node) : $node;
    //
    return $out;
}


function varDumpRet($var)
{
    ob_start();
    var_dump($var);
    return ob_get_clean();
}


function printRRet($var)
{
    ob_start();
    print_r($var);
    return ob_get_clean();
}

/**
 * Filter array by white or black list
 * @param $array
 * @param array $whiteList
 * @param array $blackList
 * @return array
 */
function array_filter_bwLists($array, $whiteList = [], $blackList = [])
{
    if ($whiteList && count($whiteList)) {
        //
        // White list rule
        return array_intersect_key($array, $whiteList);
    } else {
        //
        // Black list rule
        return array_subtraction_key($array, $blackList);
    }
}


function array_subtraction_key($arr1, $arr2)
{
    return array_diff_key($arr1, array_intersect_key($arr1, $arr2));
}

function get_preg_match($pattern, $string)
{
    preg_match($pattern, $string, $matches);
    array_shift($matches);
    return $matches;
}

/**
 * put  [0=>[0=>'key0',1=>'val0',],]
 * into ['key'=>'val',].
 * @example var_dump(akv2okv([0=>[0=>'key0',1=>'val0'],1=>[0=>'key1',1=>'val1'],2=>[0=>'key2',1=>'val2']]));
 *
 * @param $numberingArray
 * @return array
 */
function akv2okv($numberingArray)
{
    $associatedArray = [];
    array_walk($numberingArray, function (&$item) use (&$associatedArray) {
        if (!is_array($item)) return;
        $associatedArray[$item[0]] = $item[1];
//        unset($item);
    });
    return $associatedArray;
}

function hruDump($object)
{
    $out = '';
    foreach ($object as $i => $el) {
        $out .= "<p><b>$i:</b> $el\n";
    }
    return $out;
}

/**
 * @param $projectName
 * @param $projectMails array - Should contains [mailer, destination]
 * @param $theme
 * @param $data array - Should contains [userName,userMail]
 * @param $isUserCopy bool - Is user e-mail copy require (send mail for user, also?)
 * @return bool
 */
function mailSend($projectName, $projectMails, $theme, $data, $isUserCopy)
{
    foreach ($data as &$value) {
        $value = htmlspecialchars($value);
    }
    //
    // Recipients
    $ownEmail = $projectMails['destination']
        . ($isUserCopy && isset($data['userMail']) ? ',' . $data['userMail'] : '');
    //
    // Message subject
    $uniqueId = uniqid('#', true);
    $subject = "$projectName/ $theme $uniqueId";
    //
    // MIME message type
    $headers = "MIME-Version: 1.0\r\n" .
        "Content-type: text/html; charset=utf-8\r\n";
    //
    // Headers
    $headers .= "From: $data[userName] (via site) <$projectMails[mailer]>\r\n";
    //
    // Message text
    $hruData = hruDump($data);
    $msg = "<p>$theme</p><p>$hruData</p>";
    //
    // Send mail
    ini_set("SMTP", "localhost");
    ini_set("smtp_port", "25");
    return (mail($ownEmail, $subject, $msg, $headers));
}

