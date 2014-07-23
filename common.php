<?php
/**
 * @file backend / common.php
 * Created: 23.05.14 / 21:25
 */

namespace Invntrm;

define('ROOT', $_SERVER['DOCUMENT_ROOT'] . '/');
define('SRV', ROOT . '_ass/');
$C = function ($a) { return $a; };

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

function true_get ($array,$key)
{
    return isset($array[$key]) ? $array[$key] : '';
}

function true_session_start(){
    if(session_status() == PHP_SESSION_NONE) session_start();
}

/**
 * Send to callback value and key, instead of array_map, which sends only value
 */
function true_array_map($callback, $array) {
    $resultArray = [];
    foreach($array as $key => $val) {
        $resultArray[$key] = $callback($val, $key);
    }
    // \Invntrm\_d([$array, $resultArray]);
    return $resultArray;
}

function true_sort($valueOrigin)
{
    $value = $valueOrigin;
    if (empty($value) ) return [];
    if (!is_array($value)) throw \Exception('true_sort accept arrays only');
    sort($value);
    return $value;
}

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
 *
 * @param string $template - Target text
 * @param        array     (mixed)[] $vars      - Set of variables
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

function buildPage($path, $params_origin = [])
{
    if (!$path) {
        return buildPage('/404/');
    }
    $defaultPrefix = '/_views';
    list($templateWithMeta, $pageDir, $pageName) = getFileContent($path, 'html', $defaultPrefix);
    if ($templateWithMeta === false) {
        return buildPage('/404/');
    }
    list($meta, $template) = parseMetaPage($templateWithMeta);
    $out        = $template;
    $pageObject = get_require($pageDir . $pageName, '', false, $params_origin); // try execute template's logic
    $css        = getCss($pageDir . $pageName); // add css
    $css        = "<style>/* $path */" . $css . "</style>\n";
    //                                                      // L  Inject   View secret       comment
    $params = [];
    $params = array_merge($params, $_REQUEST);     // 5 DANGEROUS          add server constants
    if(isset($_SESSION))
        $params = array_merge($params, $_SESSION); // 4 UNTRUST            add server constants
    $params = array_merge($params, $_SERVER);      // 3 UNTRUST            add server constants
    $params = array_merge($params, get_defined_constants()); // 2 TRUST    DANGEROUS  add constants
    $params = array_merge($params, $params_origin);// 0 TRUST              add page call params
    $params = array_merge($params, $pageObject);   // 1 TRUST              add page php script given object
    // @todo add params filters
    //
    $paramMapping = (isset($pageObject['_PARAM_MAPPING_'])) ? $pageObject['_PARAM_MAPPING_'] : [];
    if (!isset($params['styles'])) {
        $params['styles'] = $css;
    } else {
        $params['styles'] = $css . $params['styles'];
    }
    if (isset($pageObject['_STOP_'])) {
        $stopRef = $pageObject['_STOP_']; // ref to redirect page or null for 404
        if (!$stopRef) {
            return buildPage('/404/');
        }
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
            return buildPage($pageDir . $matches[1], $params);
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
 *
 * @param string $template - Target text
 * @param        array     (mixed)[] $vars      - Set of variables
 * @param array  $paramMapping
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
 * @param bool   $isStrict
 * @param array  $params
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

function true_count($array)
{
    return (is_array($array)) ? count($array) : 0;
}

function getCss($path, $prefix = null, $isStrict = false)
{
    $path        = $prefix . preg_replace('!/$!', '', $path) . '.css';
    $isFileExist = is_file($path);
    if ($isStrict) if (!$isFileExist) {
        return false;
    }
    if ($isFileExist)
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
 * @param      $text
 * @param bool $isTrace
 * @param $text
 */
function _d($text, $isTrace = false)
{
    if(!is_bool($isTrace)) {
        $text = [$text, $isTrace];
        $isTrace = false;
    }
    file_put_contents(
        ROOT . '_logs/check.log',
        "\n"  . date(DATE_RSS)  . '>'
            . \Invntrm\varDumpRet($text)
            . ($isTrace ? "\nTrace:\n".\Invntrm\varDumpRet(debug_backtrace()) : ''),
        FILE_APPEND
    );
}

/**
 * @param $type
 * @param $text
 */
function bugReport2($type, $text)
{
    file_put_contents(ROOT . '_logs/error.log', "\n" . date(DATE_RSS) . '>' . $type . '>' . varDumpRet($text), FILE_APPEND);
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
    $fInfo       = finfo_open();
    $fInfoResult = fInfo_file($fInfo, $filePath, $typeInfo);
    return $fInfoResult;
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


function varDumpRet($var)
{
    return print_r($var, true);
    // ob_start();
    // var_dump($var);
    // return ob_get_clean();
}


function printRRet($var)
{
    ob_start();
    print_r($var);
    return ob_get_clean();
}

/**
 * Filter array by white or black list
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
        return array_intersect_key($array, $whiteList);
    } else {
        //
        // Black list rule
        return array_subtraction_key($array, $blackList);
    }
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
function array_filter_bwListsByKeys($array,$whiteList = [], $blackList = [])
{
    if($whiteList)$whiteList = array_walk($whiteList, function (&$el,&$key) use ($array){$el = ''; $key = $array[$key];});
    if($blackList)$blackList = array_walk($blackList, function (&$el,&$key) use ($array){$el = ''; $key = $array[$key];});
    return array_filter_bwLists($array,$whiteList,$blackList);
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
    //    _d(['hruDump', $out]);
    return $out;
}

/**
 * @deprecated have to use PHPMailer based solutions
 *
 * @param $projectName
 * @param $projectMails array - Should contains [mailer, destination]
 * @param $theme
 * @param $data         array - Should contains [userName,userMail]
 * @param $isUserCopy   bool - Is user e-mail copy require (send mail for user, also?)
 *
 * @return bool
 */
function mailSend($projectName, $projectMails, $theme, $data, $isUserCopy)
{
    if (empty($data['userName'])) $data['userName'] = '';
    // _d(['mailSend', $projectName, $projectMails, $theme, $data, $isUserCopy]);
    //
    // Recipients
    $ownEmail = (is_array($projectMails['destination']) ? join(',', $projectMails['destination']) : $projectMails['destination'])
        . ($isUserCopy && !empty($data['userMail']) ? ',' . $data['userMail'] : '');
    //
    // Message subject
    $uniqueId = uniqid('#');
    $subject  = "$projectName/ $theme $uniqueId";
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
    $msg     = "<p>$theme</p><p>$hruData</p>";
    //
    // Send mail
    ini_set("SMTP", "localhost");
    ini_set("smtp_port", "25");
    // _d(['before mail()', $ownEmail, $subject, $msg, $headers]);
    return (mail($ownEmail, $subject, $msg, $headers));
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
 * @param int    $length
 * @param bool   $add_dashes
 * @param string $available_sets - {l:a-z,  u:A-Z,  d:2-9,  s:specials}
 *
 * @return string
 */
function generateStrongPassword($length = 9, $add_dashes = false, $available_sets = 'luds')
{
    $sets = array();
    if (strpos($available_sets, 'l') !== false)
        $sets[] = 'abcdefghjkmnpqrstuvwxyz';
    if (strpos($available_sets, 'u') !== false)
        $sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    if (strpos($available_sets, 'd') !== false)
        $sets[] = '23456789';
    if (strpos($available_sets, 's') !== false)
        $sets[] = '!@#$%&*?';

    $all      = '';
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
    $iv_size        = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
    $iv             = mcrypt_create_iv($iv_size, MCRYPT_RAND);
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

    $msg = preg_replace_callback('#\{\}#', function($r){
        static $i = 0;
        return '{'.($i++).'}';
    }, $msg);

    return str_replace(
        array_map(function($k) {
            return '{'.$k.'}';
        }, array_keys($vars)),

        array_values($vars),

        $msg
    );
}

/**
 * @param \YandexMoney\Response\RequestPaymentResponse|string $resp
 *
 * @return string
 */
function makeErrorCode($resp)
{
    $error = (is_string($resp) ? $resp : $resp->getError());
    $error = '%MESSAGE_' . strtoupper(preg_replace('/\s+/','_',$error)) . '%';
    return $error;
}

class Exception extends \Exception {
    protected $codeExtended;

    /**
     * @return string
     */
    public function getCodeExtended()
    {
        return $this->codeExtended;
    }

    /**
     * @param string     $codeExtended
     * @param int        $description
     * @param \Exception $previous    [optional]
     * @param \Exception $numericCode [optional]
     */
    public function __construct($codeExtended, $description = null, $previous = null, $numericCode = null)
    {
        if(!$description) $description = 'нет описания';
        parent::__construct($description, $numericCode, $previous);
        $this->codeExtended = makeErrorCode($codeExtended);
    }

}
