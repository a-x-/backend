<?php
/**
 * @file backend / common.php
 * Created: 23.05.14 / 21:25
 */

namespace Invntrm;

define('ROOT', $_SERVER['DOCUMENT_ROOT'] . '/');
define('SRV', ROOT . '_ass/');
$C = function ($a) {
    return $a;
};
$_RAW_PAYLOAD = file_get_contents("php://input");
$_PAYLOAD = \Invntrm\get_parse_str($_RAW_PAYLOAD);
$_PUT = $_PAYLOAD;
///**
// * Convert error messages to Exceptions
// *
// * @param $errNo
// * @param $errStr
// * @param $errFile
// * @param $errLine
// *
// * @throws \ErrorException
// */
//function exception_error_handler($errNo, $errStr, $errFile, $errLine)
//{
//    throw new \ErrorException($errStr, $errNo, 0, $errFile, $errLine);
//}
//
//set_error_handler("exception_error_handler");
///**
// * @param $className
// */
//function __autoload($className)
//{
//    require_once(SRV . "$className.php");
//}


function true_count($array)
{
    return (is_array($array)) ? count($array) : 0;
}

/**
 * @param $array array
 * @param $key   string|int
 *
 * @return string
 * @throws \Exception
 */
function true_get($array, $key, $isStrict = true)
{
    if (!is_array($array)) {
        if ($isStrict)
            throw new \Exception('$array must be array' . varDumpRet(['array' => $array, 'key' => $key]));
        else
            return null;
    }
    return isset($array[$key]) ? $array[$key] : null;
}

function true_session_start()
{
    if (session_status() === PHP_SESSION_NONE) session_start();
}

/**
 * Send to callback value and key, instead of array_map, which sends only value
 */
function true_array_map($callback, $array)
{
    $resultArray = [];
    foreach ($array as $key => $val) {
        $resultArray[$key] = $callback($val, $key);
    }
    // \Invntrm\_d([$array, $resultArray]);
    return $resultArray;
}

function true_strtolowercase($string)
{
    return mb_strtolower($string, 'UTF-8');
}

/**
 * Capitalize (first letter of) string
 *
 * @param $string
 *
 * @return string
 */
function true_strtocap($string)
{
    return mb_uppercaseFirstLetter($string, 'UTF-8');
}

function true_sort($array, $sort_flags = SORT_REGULAR)
{
    if (empty($array)) return [];
    if (!is_array($array)) throw \Exception('true_sort accept arrays only');
    $arrayCopy = array_merge([], $array);
    sort($arrayCopy);
    return $arrayCopy;
}

/**
 * Parse post body string
 *
 * @param $str
 *
 * @return array
 */
function get_parse_str($str)
{
    $outArr = [];
    parse_str($str, $outArr);
    return $outArr;
}


function exec_node($scriptPath)
{
    global $C;
    return exec("node {$C(__DIR__)}/../../../{$scriptPath}");
}

function exec_node_json($scriptPath)
{
    return json_decode(exec_node($scriptPath), true);
}

/**
 * Decode JSON file as associative array by its path
 *
 * @param $path
 *
 * @return mixed
 */
function json_decode_file($path)
{
    return json_decode(file_get_contents($path), true);
}

function getFNameStamp($fileMame, $isPathRewriteActive = false)
{
    return ($isPathRewriteActive ? preg_replace('!^.*/!', '', $fileMame) : $fileMame) . '?t' . filemtime(ROOT . preg_replace('!\.min!i', '', $fileMame));
}

/**
 *
 * Substitution variables into placeholders in the $template
 *
 * @param string $template - Target text
 * @param        array (mixed)[] $vars      - Set of variables
 *
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
    //
    // Page meta header existing check
    if (!preg_match('!^((.+?)\s*:\s*(.+)\r?\n)+\r?\n!i', $str)) {
        return [[], $str];
    }
    $rawPage = (get_preg_match('!^(.*?)(?:\r?\n){2,}\s*(.*)$!s', $str));
    if (!true_count($rawPage)) {
        return [[], $str];
    }
    list($meta, $body) = $rawPage;
    $metaLines = preg_split('!\s*\r?\n\s*!', $meta);
    foreach ($metaLines as &$metaLine) {
        $metaLine = get_preg_match('!^(.+?)\s*:\s*(.+)$!', $metaLine);
    }
    $meta = akv2okv($metaLines);
    return [$meta, $body,];
}

function buildPage($path, $params_origin = [], $white_page_list = null, $is_allow_view = true)
{
    if ($white_page_list && !$is_allow_view && !in_array($path, $white_page_list)) {
        header("Status: 401 Access denied");
        return buildPage('/401/');
    }
    $throw404 = function () {
        header("Status: 404 Not Found");
        return buildPage('/404/');
    };
    if (!$path) {
        return $throw404();
    }
    $defaultPrefix = '/_views';
    list($templateWithMeta, $pageDir, $pageName) = getFileContent($path, 'html', $defaultPrefix);
    if ($templateWithMeta === false) {
        return $throw404();
    }
    list($meta, $template) = parseMetaPage($templateWithMeta);
    $out = $template;
    $pageObject = get_require($pageDir . $pageName, '', false, $params_origin); // try execute template's logic
    $css = getCss($pageDir . $pageName); // add css
    $css = "<style>/* $path */" . $css . "</style>\n";
    //                                                      // L  Inject   View secret       comment
    $params = [];
    $params = array_merge($params, $_REQUEST); // 5 DANGEROUS          add server constants
    if (isset($_SESSION))
        $params = array_merge($params, $_SESSION); // 4 UNTRUST            add server constants
    $params = array_merge($params, $_SERVER); // 3 UNTRUST            add server constants
    $params = array_merge($params, get_defined_constants()); // 2 TRUST    DANGEROUS  add constants
    $params = array_merge($params, $params_origin); // 0 TRUST              add page call params
    $params = array_merge($params, $pageObject); // 1 TRUST              add page php script given object
    // @todo add params filters
    //
    $paramMapping = (isset($pageObject['_PARAM_MAPPING_'])) ? $pageObject['_PARAM_MAPPING_'] : [];
    if (!isset($params['styles'])) {
        $params['styles'] = $css;
    } else {
        $params['styles'] = $css . $params['styles'];
    }
    if (!empty($meta['title'])) $params['page-name'] = $meta['title'];
    if (isset($pageObject['_STOP_'])) {
        $stopRef = $pageObject['_STOP_']; // ref to redirect page or null for 404
        if (!$stopRef) {
            return $throw404();
        }
        header("Location: $stopRef");
    }
    if (isset($params_origin['_REDIRECT_'])) {
        $redirect = $params_origin['_REDIRECT_'];
        // $time = true_is_preg_match('s$', $redirect[0]) ? (int)preg_replace('!s$!','',$redirect[0]) * 1000 : $redirect[0];
        $time = $redirect[0];
        $location = $redirect[1];
        $out = "<meta http-equiv='refresh' content='$time;$location'>" . $out;
    }
    //
    // Replace recursive call placeholders
    $out = preg_replace_callback('!' . '@([a-z_\-/\.0-9]+?)@' . '!i',
        function ($matches) use ($params, $pageDir, $defaultPrefix) {
            $match = $matches[1];
            if (!$pageDir || preg_match('!^/!', $match)) // if pageDir isn't set OR @placeholder@ start with /, than decide match absolute
                $pageDir = ROOT . $defaultPrefix;
            else
                $pageDir = "$pageDir/";
            return buildPage($pageDir . $matches[1], $params);
        },
        $out
    );
    $out = specifyTemplateExtended($out, $params, $paramMapping);
    if (isset($meta['base'])) { // if base tpl is declared
        $params['content'] = $out;
        $basePath = true_is_preg_match('^/', $meta['base']) ? $meta['base'] : "{$pageDir}/{$meta['base']}";
        return buildPage($basePath, $params);
    } else
        return $out;
}


/**
 *
 * Substitution variables into placeholders in the $template
 *
 * @param string $template - Target text
 * @param        array (mixed)[] $vars      - Set of variables
 * @param array $paramMapping
 *
 * @return          string          - Specified result
 */
function specifyTemplateExtended($template, $vars = [], $paramMapping = [])
{
    $out = $template;
    //
    // Replace simple variable placeholders
    $out = preg_replace_callback('/%([a-z0-9_\-]+?)%/i',
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
                #bugReport2("specifyTemplate()", " placeholder '$matches[1]' haven't value");
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
 * @example getFileContent('/profile','html','/path') -> content of /path/profile.html
 * @example getFileContent('... content ...') -> ... content ...
 *
 * @param $fileName__filePath
 * @param $defaultExtension
 * @param $defaultPrefix
 *
 * @return array|string
 */
function getFileContent($fileName__filePath, $defaultExtension, $defaultPrefix)
{
    $filePath = '';
    if (preg_match('!^\/[^\n]*$!', $fileName__filePath)) { // Load file if path present
        $filePath = $fileName__filePath;
        if (!preg_match('!' . $defaultPrefix . '!', $filePath)) {
            if ($filePath == '/') {
                $filePath = '/_start';
            }
            $filePath = ROOT . $defaultPrefix . $filePath;
        }
        //
        // Add extension by default if no set
        if (!preg_match('!\.!', preg_replace('!^.+/!m', '', $filePath))) {
            $filePath = preg_replace('!/$!', '', $filePath);
            $filePath .= '.' . ($defaultExtension ? $defaultExtension : 'html');
        }
        $template = (file_exists($filePath)) ? file_get_contents($filePath) : false;
    } else {
        $template = $fileName__filePath;
    }
    return [$template, dirname($filePath) . '/', preg_replace('!\..*?$!', '', basename($filePath))];
}


/**
 * Node.js like require function.
 * @example $fs = require('fs');
 * @example fs.php: $exports = []; $exports['read'] = function (name){...}
 *
 * @param        $phpFileName
 * @param string $prefix
 * @param bool $isStrict
 * @param array $params
 *
 * @return array
 */
function get_require($phpFileName, $prefix = '', $isStrict = false, $params = [])
{
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

function getCss($path_origin, $prefix = '', $is_strict = false)
{
    $path = $prefix . preg_replace('!/$!', '', $path_origin) . '.css';
    $is_exist = is_file($path);
    if ($is_strict && !$is_exist) {
        return false;
    }
    if ($is_exist)
        return file_get_contents($path);
    else
        return '';
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
 * @return string
 */
function getLogPath()
{
    $mode = (IS_DEBUG_ALX === true) ? 'dev' : 'prod';
    $server_name = preg_replace('!^testdev\.!', '', $_SERVER['SERVER_NAME']);
    $log_path = "/var/www/logs/{$server_name}/{$mode}_logs/";
    exec("mkdir -p {$log_path}");
    return $log_path;
}

/**
 * version 2
 *
 * @param        $text
 * @param bool $isTrace
 * @param string $logName
 */
function _d($text, $isTrace = false, $logName = 'check')
{
    $log_path = getLogPath();
    if (!is_bool($isTrace) && $logName == 'check') {
        $logName = $isTrace;
        $isTrace = false;
    }
    file_put_contents(
        "{$log_path}/{$logName}.log",
        "\n" . date(DATE_RSS) . '>'
        . \Invntrm\varDumpRet($text)
        . ($isTrace ? "\nTrace:\n" . \Invntrm\varDumpRet(debug_backtrace()) : ''),
        FILE_APPEND
    );
}

/**
 * version 2
 *
 * @param $type
 * @param $text
 */
function bugReport2($type, $text)
{
    $log_path = getLogPath();
    file_put_contents($log_path . '/error.log', date(DATE_RSS) . '>' . $type . '>' . varDumpRet($text) . "\n\n", FILE_APPEND);
}

/**
 * @example getFileInfo('me.svg') -> 'image/svg+xml'
 * @example getFileInfo('end.css') -> false // end.css is not exist
 *
 * @param     $filePath
 * @param int $typeInfo - http://www.php.net/manual/en/fileinfo.constants.php
 *
 * @return mixed
 */
function getFileInfo($filePath, $typeInfo = FILEINFO_MIME_TYPE)
{
    $fInfo = finfo_open();
    $fInfoResult = fInfo_file($fInfo, $filePath, $typeInfo);
    return $fInfoResult;
}


/**
 * Вычислить значение многомерного массива, ключ которого задан строкой key1.key2.key3. ... keyN
 * @example evalArrayByPath('a.b.c',[a=>[b=>[c=>1]]]) -> 1
 *
 * @param $path
 * @param $root
 *
 * @return mixed|bool
 */
function evalArrayByPath($path, $root)
{
    $dirs = preg_split('/\./', $path);
    for ($i = 0, $l = true_count($dirs); $i < $l; ++$i) {
        $dir = $dirs[$i];
        if (isset($root[$dir])) $root = $root[$dir];
        else return false;
    }
    return $root;
}

/**
 * Специализировать маску (подставить одно из значений вместо указанного плейсхолдера)
 *
 * @param $mask        string - 'blah blah %user_name%, hi!'
 * @param $placeholder string - 'user_name'
 * @param $value       string   $user_name
 *
 * @return string
 */
function specializeMask2($mask, $placeholder, $value)
{
    return str_replace('%' . $placeholder . '%', $value, $mask);
}

/**
 * Return "String of some text" from some "sTrIng OF some TeXT".
 * First letter of none unicode text turn to uppercase,
 * another letters turn to lowercase
 *
 * @param $string
 *
 * @return string
 */
function uppercaseFirstLetter($string)
{
    return ucfirst(strtolower($string));
}

/**
 * Return "Строку некоторого текста", from some "сТрокУ НЕКОТОРОГО текста"
 * First letter of unicode text turn to uppercase, another letters turn to lowercase
 *
 * @param $string
 *
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

/**
 * @example varDumpRet(['1'=>1,'2'=>2], true) --> [ "1" => 1     "2" => 2 ]
 * @example varDumpRet(['1'=>1,'2'=>2]) -->
 *          Array
 *          (
 *             [1] => 1
 *             [2] => 2
 *          )
 *
 * @param $var
 * @param $isPretty
 *
 * @return mixed
 */
function varDumpRet($var, $isPretty = false)
{
    if ($isPretty) {
        $out = print_r($var, true);
        $out = preg_replace('!\n!', ' ', $out);
        $out = preg_replace('!\[(.*?)\]!', '"$1"', $out);
        $out = preg_replace('!Array\s*\((.*)\)!i', '[$1]', $out);
    } else {
        ob_start();
        var_export($var);
        return ob_get_clean();
    }
    return $out;
}

/**
 * @deprecated by print_r($var, true)
 * @param $var
 *
 * @return string
 */
function printRRet($var)
{
    ob_start();
    print_r($var);
    return ob_get_clean();
}

/**
 * Filter array by white or black list
 * @example echo (varDumpRet(array_filter_bwLists(['1'=>1,'2'=>2,'3'=>3], ['1'=>3,'2'=>4] ,[]), true) . '==' . '["1"=>1,"2"=>2]');
 * @example echo (varDumpRet(array_filter_bwLists(['1'=>1,'2'=>2,'3'=>3], ['1'=>3,'2'=>4] ,['1'=>5]), true) . '==' . '["2"=>2]');
 *
 * @param       $array
 * @param array $whiteList
 * @param array $blackList
 *
 * @return array
 */
function array_filter_bwLists($array, $whiteList = [], $blackList = [])
{
    if ($whiteList && true_count($whiteList)) {
        //
        // White list rule
        $array = array_intersect_key($array, $whiteList);
    }
    //
    // Black list rule
    if ($blackList) $array = array_subtraction_key($array, $blackList);
    return $array;
}

/**
 * @example  array_filter_bwListsByKeys(['a'=>1,'b'=>2,'c'=>3], ['a'], null) --> ['a'=>1]
 * @example  array_filter_bwListsByKeys(['a'=>1,'b'=>2,'c'=>3], null, ['a']) --> ['b'=>2,'c'=>3]
 *
 * @param       $array
 * @param array $whiteList
 * @param array $blackList
 *
 * @return array
 */
function array_filter_bwListsByKeys($array, $whiteList = [], $blackList = [])
{
    foreach (['whiteList', 'blackList'] as $type) {
        $type2 = $type . 'KeyVal';
        $$type2 = [];
        if (!$$type) $$type = [];
        foreach ($$type as $item) {
            ${$type2} [$item] = null;
        }
    }
    return array_filter_bwLists($array, $whiteListKeyVal, $blackListKeyVal); // DONT TOUCH KeyVal!!!
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
 * Motivation: keep RegExp in valid form. PhpStorm regexp pad can only valid clear regexp process
 * @example true_get_preg_match('^/', '../gfd/')
 *
 * @param        $pattern
 * @param        $subject
 * @param        $affix
 * @param string $delimiter
 *
 * @return mixed
 */
function true_get_preg_match($pattern, $subject, $affix = '', $delimiter = '!')
{
    return get_preg_match("{$delimiter}{$pattern}{$delimiter}{$affix}", $subject);
}

function true_is_preg_match($pattern, $subject, $affix = '', $delimiter = '!')
{
//    _d(['true_is_preg_match','p'=>$pattern,'s'=>$subject,'a'=>$affix]);
    return preg_match("{$delimiter}{$pattern}{$delimiter}{$affix}", $subject);
}

/**
 * Return unshifted array (none modify)
 * a + [b,c] -> [a,b,c]
 *
 * @param $var
 * @param $array
 *
 * @return mixed
 */
function get_array_unshift($var, $array)
{
    $array2 = $array;
    array_unshift($array2, $var); // no returns new $array, but modify input array
    return $array2;
}

/**
 * put  [0=>[0=>'key0',1=>'val0',],]
 * into ['key'=>'val',].
 * @example var_dump(akv2okv([0=>[0=>'key0',1=>'val0'],1=>[0=>'key1',1=>'val1'],2=>[0=>'key2',1=>'val2']]));
 *
 * @param $numberingArray
 *
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

/**
 * Get associative array in human readable form
 *
 * @param     $array
 * @param int $level
 *
 * @return string
 */
function hruDump($array, $level = 0)
{
    $tpl = '<div style="border-left:20px #e5e5e5 solid;">%out%</div>';
    $out = '';
    foreach ($array as $i => $el) {
        if (is_array($el)) {
            $el = hruDump($el, $level + 1);
        }
        $out .= "<p style='margin-left: 1em'><b>$i:</b> $el\n";
    }
    $out = specializeMask2($tpl, 'out', $out);
    return $out;
}

/**
 * Send project specific mail with message dump
 *
 * @param $data         array - Should contains [userName,userMail]
 *
 * @param $to
 * @param $consts
 * @param $theme
 *
 * @return bool
 */
function mailDump($data, $to, $consts, $theme)
{
    $message = hruDump($data);
    return mailProject($message, $to, '', $consts, $theme);
}

/**
 * Send project specific mail
 *
 * @param $message
 * @param $to
 * @param $fromName
 * @param $consts
 * @param $theme
 *
 * @return bool
 */
function mailProject($message, $to, $fromName, $consts, $theme)
{
    $fromName = transliterateCyr($fromName);
    $fromName = $fromName ? "$fromName (via site)" : true_get($consts, 'PROJECT_NAME_STUB');
    $mailer = true_get($consts, 'MAILER_EMAIL');
    $from = "$fromName <{$mailer}>";
    //
    // MIME message type
    $headers
        = "MIME-Version: 1.0\r\n"
        . "Content-type: text/html; charset=utf-8\r\n"
        . "From: $from\r\n";
    //
    // Message subject
    $uniqueId = uniqid('#');
    $subject = "$theme $uniqueId";
    //
    // Send mail
    ini_set("SMTP", "localhost");
    ini_set("smtp_port", "25");
    return (mail($to, $subject, $message, $headers));
}


/**
 * Generates a strong password of N length containing at least one lower case letter,
 * one uppercase letter, one digit, and one special character. The remaining characters
 * in the password are chosen at random from those four sets.
 *
 * The available characters in each set are user friendly - there are no ambiguous
 * characters such as i, l, 1, o, 0, etc. This, coupled with the $add_dashes option,
 * makes it much easier for users to manually type or speak their passwords.
 *
 * Note: the $add_dashes option will increase the length of the password by
 * floor(sqrt(N)) characters.
 *
 * @param int $length
 * @param bool $add_dashes
 * @param string $available_sets - {l:a-z,  u:A-Z,  d:2-9,  s:specials}
 *
 * @return string
 */
function generateStrongPassword($length = 9, $add_dashes = false, $available_sets = 'luds')
{
    $sets = [];
    if (strpos($available_sets, 'l') !== false)
        $sets[] = 'abcdefghjkmnpqrstuvwxyz';
    if (strpos($available_sets, 'u') !== false)
        $sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    if (strpos($available_sets, 'd') !== false)
        $sets[] = '23456789';
    if (strpos($available_sets, 's') !== false)
        $sets[] = '!@#$%&*?';

    $all = '';
    $password = '';
    foreach ($sets as $set) {
        $password .= $set[array_rand(str_split($set))];
        $all .= $set;
    }

    $all = str_split($all);
    for ($i = 0; $i < $length - true_count($sets); $i++)
        $password .= $all[array_rand($all)];

    $password = str_shuffle($password);

    if (!$add_dashes)
        return $password;

    $dash_len = floor(sqrt($length));
    $dash_str = '';
    while (strlen($password) > $dash_len) {
        $dash_str .= substr($password, 0, $dash_len) . '-';
        $password = substr($password, $dash_len);
    }
    $dash_str .= $password;
    return $dash_str;
}


/**
 * Simplify array structure
 * In lo-dash it has different name: flatten
 * @example [] -> null
 * @example [[]] -> null
 * @example [[1]] -> 1
 * @example [[1],[]] -> [1,null]
 * @example [[[1]],2] -> [1,2]
 *          etc...
 *
 * @param $arr
 *
 * @return array|null
 */
function recursiveDegenerateArrOptimize($arr)
{
    if (is_array($arr)) {
        foreach ($arr as $arr_key => $arr_val) {
            $arr[$arr_key] = recursiveDegenerateArrOptimize($arr_val);
        }
        if (true_count($arr) === 1)
            foreach ($arr as $arr_val)
                $arr = $arr_val;
        elseif (!true_count($arr))
            $arr = null;
    }
    return $arr;
}


/**
 * @link https://gist.github.com/vindia/1476814
 *
 * @param $string
 *
 * @return mixed
 */
function transliterateCyr($string, $isBackward = false)
{
    $roman = ["Sch", "sch", 'Yo', 'Zh', 'Kh', 'Ts', 'Ch', 'Sh', 'Yu', 'ya', 'yo', 'zh', 'kh', 'ts', 'ch', 'sh', 'yu', 'ya', 'A', 'B', 'V', 'G', 'D', 'E', 'Z', 'I', 'Y', 'K', 'L', 'M', 'N', 'O', 'P', 'R', 'S', 'T', 'U', 'F', '', 'Y', '', 'E', 'a', 'b', 'v', 'g', 'd', 'e', 'z', 'i', 'y', 'k', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'u', 'f', '', 'y', '', 'e'];
    $cyrillic = ["Щ", "щ", 'Ё', 'Ж', 'Х', 'Ц', 'Ч', 'Ш', 'Ю', 'я', 'ё', 'ж', 'х', 'ц', 'ч', 'ш', 'ю', 'я', 'А', 'Б', 'В', 'Г', 'Д', 'Е', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Ь', 'Ы', 'Ъ', 'Э', 'а', 'б', 'в', 'г', 'д', 'е', 'з', 'и', 'й', 'к', 'л', 'м', 'н', 'о', 'п', 'р', 'с', 'т', 'у', 'ф', 'ь', 'ы', 'ъ', 'э'];
    return $isBackward ? str_replace($roman, $cyrillic, $string) : str_replace($cyrillic, $roman, $string);
}


/**
 * @example $code = encrypt_data('12345','Привет Ясень! 45');
 * @example require_once 'Mq.php';
 * @example $ew1 = (new \Mq())->newR('user[1]?ym_token2_encrypted=*,ym_token2_iv=*','ss',$code);
 * @example $ew2 = (new \Mq())->newR('user[1]?ym_token2_encrypted,ym_token2_iv');
 * @example $code = decrypt_data('12345',$ew2['ym_token2_iv'],$ew2['ym_token2_encrypted']);
 * @example $code;
 *
 * @param $key
 * @param $text
 *
 * @return string
 */
function encrypt_data($key, $text)
{
    $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
    $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
    $encrypted_text = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $key, $text, MCRYPT_MODE_ECB, $iv);
    return [$encrypted_text, $iv];
}

/**
 * @param $key
 * @param $iv
 * @param $text
 *
 * @return string
 */
function decrypt_data($key, $iv, $text)
{
    //    $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
    //    $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
    $decrypted_text = mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $key, $text, MCRYPT_MODE_ECB, $iv);
    return rtrim($decrypted_text, "\0");
}


/**
 * Python like string formating.
 *
 * - The order doesn't matter,
 * - You can omit the name/number if you want it to simply increment (the first {} matched will be transformed into {0}, etc),
 * - You can name your parameters,
 * - You can mix the three other points.
 *
 * Note, than there is internal php string {} templating constructions
 *
 * As PHP doesn't really have a proper alternative to str.format in Python,
 * I decided to implement my very simple own which as most of the basic functionnalitites of the Python's one.
 * @link http://stackoverflow.com/questions/16632067/php-equivalent-of-pythons-str-format-method#answer-17372566
 *
 * @example
 * # Hello foo and bar
 * echo format('Hello {} and {}.', array('foo', 'bar'));
 *
 * @example
 * # Hello Mom
 * echo format('Hello {}', 'Mom');
 *
 * @example
 * # Hello foo, bar and foo
 * echo format('Hello {}, {1} and {0}', array('foo', 'bar'));
 *
 * @example
 * # I'm not a fool nor a bar
 * echo format('I\'m not a {foo} nor a {}', array('foo' => 'fool', 'bar'));
 *
 */
function format($msg, $vars)
{
    $vars = (array)$vars;

    $msg = preg_replace_callback('#\{\}#', function ($r) {
        static $i = 0;
        return '{' . ($i++) . '}';
    }, $msg);

    return str_replace(
        array_map(function ($k) {
            return '{' . $k . '}';
        }, array_keys($vars)),

        array_values($vars),

        $msg
    );
}


/**
 * @deprecated
 * @param \YandexMoney\Response\RequestPaymentResponse|string $resp
 *
 * @return string
 */
function makeErrorCode($resp)
{
    $error = (is_string($resp) ? $resp : $resp->getError());
    $error = '%MESSAGE_' . strtoupper(preg_replace('/\s+/', '_', $error)) . '%';
    return $error;
}

/**
 * @deprecated
 * Class Exception
 * @package Invntrm
 */
class Exception extends \Exception
{
    protected $codeExtended;

    /**
     * @param string $codeExtended
     * @param string $description
     * @param \Exception $previous [optional]
     * @param int $numericCode [optional]
     */
    public function __construct($codeExtended, $description = null, $previous = null, $numericCode = null)
    {
        if (!$description) $description = 'нет описания';
        parent::__construct($description, $numericCode, $previous);
        $this->codeExtended = makeErrorCode($codeExtended);
    }

    /**
     * @return string
     */
    public function getCodeExtended()
    {
        return $this->codeExtended;
    }

}

class ExtendedException extends \Exception
{
    protected $codeExtended;

    /**
     * @param string $codeExtended
     * @param string $description
     * @param \Exception $previous [optional]
     * @param int $numericCode [optional]
     */
    public function __construct($codeExtended, $description = null, $previous = null, $numericCode = null)
    {
        if (!$description) $description = 'Нет описания';
        if (!is_string($description)) $description = varDumpRet($description);
        parent::__construct($description, $numericCode, $previous);
        $this->codeExtended = ($codeExtended);
    }

    /**
     * @return string
     */
    public function getCodeExtended()
    {
        return $this->codeExtended;
    }

    public function getTraceAsStringImproved()
    {
        $rawTrace = parent::getTraceAsString();
        return preg_replace('!\((.*?)\):!', ':$1', $rawTrace);
    }

}

class ExtendedInvalidArgumentException extends \InvalidArgumentException
{
    protected $codeExtended;

    /**
     * @param string $codeExtended - string error identification
     * @param string $description - string human readable description
     * @param \Exception $previous [optional] - previous exception pointer
     * @param int $numericCode [optional] - number error identification
     */
    public function __construct($codeExtended, $description = null, $previous = null, $numericCode = null, $arguments = null)
    {
        if (!$description) $description = 'Нет описания';
        if ($arguments) $description .= "\n\nАргументы:\n" . varDumpRet($arguments);
        parent::__construct($description, $numericCode, $previous);
        $this->codeExtended = ($codeExtended);
    }

    /**
     * @return string
     */
    public function getCodeExtended()
    {
        return $this->codeExtended;
    }

    public function getTraceAsStringImproved()
    {
        $rawTrace = parent::getTraceAsString();
        return preg_replace('!\((.*?)\):!', ':$1', $rawTrace);
    }

}


/**
 * Process API request exception
 * @todo add mappings
 *
 * @param $e                      \Exception|\Invntrm\ExtendedException|\Invntrm\ExtendedInvalidArgumentException
 * @param $endpoint               string
 * @param $endpoint_message       string
 * @param $endpoint_code_extended string
 *
 * @return array
 */
function processException(\Exception $e, $endpoint = '', $endpoint_message = '', $endpoint_code_extended = '')
{
    \Invntrm\bugReport2($endpoint, ['Pre error record', $endpoint, $endpoint_message, $endpoint_code_extended, $e]);

    global $_RAW_PAYLOAD;
    $method = strtolower($_SERVER['REQUEST_METHOD']);
    $e_str_error_id = (method_exists($e, 'getCodeExtended') ? $e->getCodeExtended() : $e->getCode());
    $e_str_message = $e->getMessage();
    //
    $str_error_id = $endpoint_code_extended ? $endpoint_code_extended : $e_str_error_id;
    $str_message = $endpoint_message ? $endpoint_message : $e->getMessage();
    $num_code = $e->getCode();
    $error_object = [
        'error' => $str_error_id,
        'error_message' => $str_message,
        'request_method' => $method,
        'request_string' => $endpoint,
        'query_params' => $_REQUEST,
        'payload_params' => true_is_preg_match('json', $_SERVER['HTTP_CONTENT_TYPE'])
            ? json_decode($_RAW_PAYLOAD, true) : $_PAYLOAD
    ];
    if (IS_DEBUG_ALX === true) {
        $error_object = array_merge($error_object, [
            'error_debug' => [
                'error' => $e_str_error_id,
                'error_message' => $e_str_message,
                'error_debug' => $e
            ]
        ]);
    }
    if (!empty($num_code)) {
        $error_object = array_merge($error_object, [
            'error_code' => $num_code
        ]);
    }
    \Invntrm\bugReport2($endpoint, $error_object);
    return $error_object;
}


function sanitize_validate_name($user_name, $required_full_name_level = 0)
{
    require_once __DIR__ . '/../../../vendor/igrizzli/NameCaseLib/Library/NCLNameCaseRu.php';
    $name = $user_name;
    $name = str_replace('&nbsp;', ' ', $name);
    $name = preg_replace('![^a-zа-яё\-\'\s]!iu', '', $name);
    $name = preg_replace('!\s+!', ' ', trim($name));
    $name = substr($name, 0, 256); // bad magic length const referenced with user DB's table
    $name = \Invntrm\transliterateCyr($name, true); // Latin to Cyrillic transliterate
    $name = \Invntrm\true_strtocap($name);
    if (empty($name)) return '';
    $nameLib = new \NCLNameCaseRu();
    $nameLib->q($name);
    $nameParts = ['', '', ''];
    $nom = \NCL::$IMENITLN; // nominative -- Именительный падеж
    foreach ($nameLib->getWordsArray() as $namePart) {
        switch ($namePart->getNamePart()) {
            case('N'):
                $nameParts[1] = \Invntrm\true_strtocap($namePart->getNameCase($nom));
                break;
            case('S'):
                $nameParts[0] = \Invntrm\true_strtocap($namePart->getNameCase($nom));
                break;
            case('F'):
                $nameParts[2] = \Invntrm\true_strtocap($namePart->getNameCase($nom));
                break;
        }
    }
    //
    // Strict checking part being there
    if ($required_full_name_level >= 1 && !$nameParts[0]) return false;
    if ($required_full_name_level >= 2 && !$nameParts[1]) return false;
    if ($required_full_name_level === 3 && !$nameParts[2]) return false;
    //
    $name_norm = trim(join(' ', $nameParts));
    $nnc = count(preg_split('!\s+!', $name_norm));
    $nc = count(preg_split('!\s+!', $name));
    if ($nnc < $nc) return $name;
    //
    return $name_norm;
}

function sanitize_validate_email($email)
{
    $email = true_strtolowercase(filter_var(filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL), FILTER_SANITIZE_EMAIL));
    return $email;
}

function sanitize_validate_phone($phone)
{
    $phone = preg_replace('/^\s*(8|7)/', '+7', preg_replace('/[^0-9]/', '', $phone));
}

/**
 * Get a Gravatar URL for the email address of connected user
 * Gravatar is the #1 (free) provider for email address based global avatar hosting.
 * The URL returns always a .jpg file !
 * For deeper info on the different parameter possibilities:
 * @see http://de.gravatar.com/site/implement/images/
 *
 * @param int|string $s Size in pixels, defaults to 50px [ 1 - 2048 ]
 * @param string $d Default image set to use [ 404 | mm | identicon | monsterid | wavatar ]
 * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
 * @source http://gravatar.com/site/implement/images/php/
 *
 * @return string
 */
function get_gravatar_image_url($email, $s = 50, $d = 'mm', $r = 'g')
{
    if ($email != '') {
        // the image url (on gravatar servers), will return in something like
        // http://www.gravatar.com/avatar/205e460b479e2e5b48aec07710c08d50?s=80&d=mm&r=g
        // note: the url does NOT have something like .jpg
        return 'http://www.gravatar.com/avatar/' . md5(strtolower(trim($email))) . "?s=$s&d=$d&r=$r&f=y";
    } else {
        return '';
    }
}

/**
 * Склонение числительных (для русского языка)
 *
 * Принимает число и выбирает соответствующее склонение числительного. Всего 3 варианта, которые
 * соответствуют числам 1, 2 и 5.
 *
 * @param int $number
 * @param string $one
 * @param string $two
 * @param string $five
 * @return string
 */
function decline_numeral ($number, $one, $two, $five) {
    if (($number - $number % 10) % 100 != 10) {
        if ($number % 10 == 1) { // trailing 1
            $result = $one;
        } elseif ($number % 10 >= 2 && $number % 10 <= 4) { // trailing 2, 3 or 4
            $result = $two;
        } else {
            $result = $five;
        }
    } else {
        $result = $five;
    }
    return $result;
}

/**
 * @param $array
 * @param $path
 * @return array|mixed|null
 */
function array_deep_get($array, $path) {
    $keys = preg_split('!\.!',$path);
    foreach($keys as $key) {
        if(!$array) return null;
        $array = $array[$key];
    }
    return $array;
}
