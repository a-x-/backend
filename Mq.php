<?php
/**
 * ** Goal: ИМПОРТИРОВАНО ИЗ SP 21-го МАРТА 2013-го **
 * ** Sp: ИМПОРТИРОВАНО ИЗ Goal 23-го августа 2013-го **
 * ** COMMON: ИМПОРТИРОВАНО ИЗ SP 26-го МАЯ 2014-го **
 *
 * Created with PhpStorm.
 * @author Inventorem
 * @time 22.12.12 / 14:00
 *
 * It uses mysqli driver
 */

require_once('_.php');

/**
 * Class Mq_Mode is  class Mq internal ENUM
 */
class Mq_Mode {
    const NOSMART_ENDDATA = 0;// 0|00
    const NOSMART_IRESULT = 1;// 0|01 // iterator internal MySQL result
    const NOSMART_STMT = 2;   // 0|10
    const SMART_ENDDATA = 4;  // 1|00
};
/**
 * myMySQLLib
 * TODO добавить пагинацию
 * @version 5.2.2 Note: insert notation changed!
 */
class Mq
{
    var $x; // conte[x]t
    // todo преобразовать к виду структуры типа x (conte[x]t)
    var $hndl;
    var $isLoggingRequire;
    var $stmt;
    var $schemeName;


/**
 * Construct
 *
 * @param string $schemeName        имя "базы данных" (способствует использованию нескольких "баз данных"[в рамках терминов mySQL])
 * @param bool $isLoggingNeed     выбрасавыть на клиента все логи
 * @param bool $x
 */
function Mq($schemeName = '', $x = false, $isLoggingNeed = false)
{
    $this->isLoggingRequire = $isLoggingNeed;
    $this->x = $x;
    $this->schemeName = $schemeName;
    if (!$schemeName) $schemeName = ___("SCHEME_NAME_DEFAULT"); // По умолчанию берётся из settings.ini
    $this->hndl = new mysqli(); // Параметры устанавливаются из php-conf
    $this->hndl->real_connect();
    $this->hndl->select_db($schemeName);

    $this->errorCheck('Mq::Mq initialization not success! {' . (__FILE__ . ':' . __LINE__ . ' ' . __FUNCTION__ . '()') . '}');

    /* MANUAL
     * Очень важно выставление правильной кодировки
     * В настройках mySQL-сервера (my.conf) или php-командой из mySQL API:
     * $this->hndl->real_query('set names utf8');
     */
}

function reqPreprocessor($req)
{
    $tmp = $req;
    $req = preg_replace_callback('/^([a-z\.\-_]+)\.sql$/i', function ($matches) {
        return file_get_contents("sql-reqs/$matches[1].sql");
    },$req);
    $req = preg_replace('!\[(?:SCHEME_NAME|TABLE_SCHEMA)\]!', '"' . $this->schemeName . '"', $req); // Название "базы данных" (в терминах mySQL)
    $req = preg_replace('!\[SCHEME_NAME_DEFAULT\]!', '"' . ___("SCHEME_NAME_DEFAULT") . '"', $req); // Название "базы данных" (в терминах mySQL)

    return $req;
}

/**
 * @deprecated
 * Запрос [нативный] [общий] [классический] [ресурсный] [буфферизированный/не_буфферизированный]
 *
 * @param      $req
 * @param bool $ifBufferingNeed
 *
 * @return bool|mysqli_result
 */
function q($req, $ifBufferingNeed = false)
{
    /* MANUAL
     *    * Небуферизированный результат.
     * В этом случае вы можете начинать читать результаты, не дожидаясь пока mysql сервер получит результат полностью.
     *
     * Преимущества:
     *    Результат можно начинать читать раньше, сокращается время ожидания;
     *    Результат не занимает место в оперативной памяти.
     *
     * Недостатки:
     *    НЕВОЗМОЖНО УЗНАТЬ, СКОЛЬКО СТРОК ПОЛУЧЕНО; (по всей видимости, спорно)
     *    Невозможно передвигаться к определенному результату, то есть можно читать данные только с начала и по порядку;
     *    Нельзя выполнять других запросов, пока не закрыт этот результат.
     */
    $req = $this->reqPreprocessor($req);
    $result = $this->hndl->query($req, $ifBufferingNeed ? MYSQLI_STORE_RESULT : MYSQLI_USE_RESULT);

    if ($this->isLoggingRequire) $this->messageLog("<div><b>sqlLine: </b>$req</div>" . "<b>sqlResxResult: </b>" . varDumpRet($result));

    if (!$result) {
        $this->errorLog("Mq::q: mySQL query`s hadn`t successful return! req=$req { " . (__FILE__ . ':' . __LINE__ . ' ' . __FUNCTION__ . '() }'));

        return false;
    }

    return $result;
}

/**
 * Запрос [нативный] [общий] [классический] [НЕ ресурсный] [буфферизированный/не_буфферизированный]
 * @deprecated
 *
 * @param      $req
 * @param bool $isHeuristicsNeed
 * @param bool $ifBufferingNeed
 *
 * @return int|mixed
 */
function qq($req, $isHeuristicsNeed = true /* «smart» */, $ifBufferingNeed = false)
{
    $res = $this->q($req, $ifBufferingNeed);
    if (preg_match("!^(INSERT|UPDATE)!", $req))
        return $this->hndl->insert_id;
    if (!$res)
        return 0;
    if (!is_object($res))
        return true;
    $i = 0;
    while ($row[$i++] = $res->fetch_assoc()) ;
    array_pop($row);
    if ($isHeuristicsNeed) $row = recursiveDegenerateArrOptimize($row);
    if ($this->isLoggingRequire) $this->messageLog("[DEBUG LOG] <b>sqlResult: </b>" . varDumpRet($row));

    return $row;
}

/**
 * Запрос [НЕ нативный] [НЕ общий (выборка)]  [классический] [ресурсный/не_ресурсный] [НЕ буфферизированный]
 * @deprecated
 * @param string $req              Упрощенный запрос
 * @param bool $isEndDataNeed       If false then $isHeuristicsNeed is none using arg
 * @param bool $isHeuristicsNeed
 *
 * @return array|int|mixed
 */
function r($req, $isEndDataNeed = true, $isHeuristicsNeed = true /* «smart» */)
{
    $out = $this->parseAlxMqSyntax($req);
    return $isEndDataNeed ? $this->qq($out, $isHeuristicsNeed) : $this->q($out);
}

    /**
     * Запрос [НЕ нативный] [НЕ общий (выборка)]  [НЕ классический(placeholders)] [ресурсный/не_ресурсный] [абстрактно- ресурсный/не_ресурсный]
     *
     * Может работать с рядом запросов, разделённых символами !;\s*!
     *
     * ! для table[where]?col=val /UPDATE-req/ порядока плейсхолдеров нарушается
     * ОСТОРОЖНО! Не перепутать UPDATE и INSERT
     *
     * @param string $req
     * @param string $sigma               При выполнении серии запросов нумеруется сквозным образом
     * @param array|bool $params
     * @param int|\Mq_Mode $mode
     * @param bool $isLoggingRequire
     *
     * @return string|array|bool|mysqli_result|mysqli_stmt mysqli_result
     */
function newR($req, $sigma = '', $params = false, $mode = Mq_Mode::SMART_ENDDATA, $isLoggingRequire = false)
{
//* @internal param bool $isHeuristicsNeed
//* @internal param bool $isEndDataNeed
//* @internal param bool $isNeedRetMqStmt
    $isHeuristicsNeed = $mode >> 2; // is «smart»
    $isEndDataNeed =  ($mode & bindec('011')) == 0;
    // idIResultNeed = $mode & bindec('011') == 1; // *Do not to delete*
    $isNeedRetMqStmt = ($mode & bindec('011')) == 2;
    // echo "['$req'; $mode-->$isHeuristicsNeed, $isEndDataNeed, $isNeedRetMqStmt]";
    $out = $this->parseAlxMqSyntax($req, $isLoggingRequire);
    return $isEndDataNeed ? $this->newQQ($out, $sigma, $params, $isHeuristicsNeed, $isLoggingRequire) : $this->newQ($out, $sigma, $params, $isNeedRetMqStmt, $isLoggingRequire);
}

/**
 * Запрос [нативный] [общий]  [НЕ классический(placeholders)] [ресурсный] [абстрактно- ресурсный/не_ресурсный]
 *
 * Безопасный запрос с плейлсхолдерами.
 *      Полезен в случае получения GET|POST-аргументов до соединения с БД.
 * Позволяет совершать итерации fetch снаружи
 * Может позволять использовать execute снаружи
 *
 * @param $req string Запрос-заготовка с плейсхолдерами
 * @param $sigma string Строка кодов типов параметров
 * @param $params array Параметры
 * @param $isNeedRetMqStmt bool Вернуть только stmt, чтобы воспользоваться execute снаружи
 * @param bool $isLoggingRequire
 *
 * @return mysqli_result|mysqli_stmt mysqli_result|bool -- Ресурс-класс, позволяющий совершать итерации fetch снаружи
 */
function newQ($req, $sigma = "", $params = array(), $isNeedRetMqStmt = false, $isLoggingRequire = false)
{
    $isLoggingRequire = $isLoggingRequire || $this->isLoggingRequire;
    if ($isLoggingRequire) $this->messageLog("<b>sqlLine-RAW: </b>$req; <b>Параметры</b><br>" . varDumpRet($params));
    $req = $this->reqPreprocessor($req);

    if ($isLoggingRequire) $this->messageLog("<b>sqlLine(after 1th proc): </b>$req; ");

    $this->stmt = $stmt = $this->hndl->prepare($req);
    if (!$stmt) {
        $this->errorLog('mq class: $stmt don`t calculated! Req:'.varDumpRet($req));
        return false;
    }
    if ($isNeedRetMqStmt) return $stmt;

    if ($sigma) {
        if (!is_array($params)) $params = array($params);
        if (strlen($sigma) != count($params)) $this->errorLog("mq class: '$sigma' !~ ".varDumpRet($params).'; req: "'.varDumpRet($req).'"');
        if (count($params)) {
            array_unshift($params, $sigma); // Расширяем начальным элементом, содержащим сигнатуру
            $tmp = array(); // Преобразуем строки в ссылки (требуется функции call_user_func_array)
            foreach ($params as $key => $value) $tmp[$key] = & $params[$key]; // ...
            call_user_func_array(array($stmt, 'bind_param'), $tmp); // Запускаем $stmt->bind_param с праметрами из массива
        }
    }
    $stmt->execute(); $result = $stmt->get_result(); // Выполняем, получаем результаты
    if ($isLoggingRequire && !$result->num_rows) $this->messageLog('mq class: result is empty! Req:'.varDumpRet($req));
        return $result;
}

/**
 * Запрос [нативный] [общий] [НЕ классический(placeholders)] [НЕ ресурсный] [абстрактно- ресурсный/не_ресурсный]
 *
 * Безопасный запрос с плейлсхолдерами.
 *      Полезен в случае получения GET|POST-аргументов до соединения с БД.
 *
 * Выдаёт готовый массив-результат.
 * Может позволять конвертировать деградированный массивные уровни в массивы меньшей степени,
 *    вплоть до возвращения скалярных переменных
 * Может позволять использовать execute снаружи
 *
 * @param      $req
 * @param      $sigma
 * @param      $params
 * @param bool $isHeuristicsNeed
 * @param bool $isLoggingRequire
 *
 * @return array|bool|mixed
 */
function newQQ($req, $sigma = "", $params = array(), $isHeuristicsNeed = true /* «smart» */, $isLoggingRequire = false)
{
    $isLoggingRequire = $isLoggingRequire || $this->isLoggingRequire;
    $row = array();
    $res = $this->newQ($req, $sigma, $params);
    if (preg_match("!^INSERT!", $req))
        return $this->hndl->insert_id; // При таких запросах единственное, что мб интересно: id записи
    elseif (preg_match("!^UPDATE!", $req))
        return preg_replace("!.*WHERE.*`?id`?=([0-9+]).*!", "$1", $req); // При таких запросах единственное, что мб интересно: id записи
    if (!$res)
        return false;

    for ($i = 0; $tmp = $res->fetch_array(MYSQLI_ASSOC); $i++) $row[$i] = $tmp;
    if ($isHeuristicsNeed) $row = recursiveDegenerateArrOptimize($row);
    if ($isLoggingRequire) $this->messageLog("<b>sqlResult: </b>" . varDumpRet($row));

    return $row;
}


    /**
     * Разбор запросов упрощенной нотации доступа к SQL-базам данных
     *
     * (!) Для UPDATE-запроса происходит смена порядка подстановки в плейсхолдеры
     * ОСТОРОЖНО! Не перепутать UPDATE и INSERT
     *
     * * Может работать с рядом запросов, разделённых символами ;\s*
     * Наличие полей в обрабатываемых таблицах проверяется, если это необходимо (необходимо наличие information_schema)
     * * Добавление |.: (ASC) или |:. (DESC) в конец позволяет отсортировать результаты SELECT-запроса
     *
     * TODO внедрить пересечения по свойству (пересечение классов, при котором один из классов выступает задаваемым свойством другого) // page[@prod=main] page, являющаяся main для prod
     * TODO кстати сделать автоматическое формирование умной структуры данных для конструкции пересечения по свойству {page1:{prop1,,,prod:{prep1,,,}},,,}
     *
     * TODO внедрить следование (один класс выступает родительским для другого, при этом для их связывания испрользуется поле дочернего класса родКласс_id) // prod \\ theme \\ video
     * TODO кстати сделать автоматическое формирование умной структуры данных для конструкции следования {prod1:{theme1:{videoName1:{prop1,,,},,,},,,},,,}
     *
     * TODO если у класса определено поле (поле в таблице бд) name использовать его для формирования ассоциативного массива-результата вместо обычного упорядоченного перечисления
     *
     * @example user[nm='alx']?pic                      SELECT
     * @example user[n=1&&L=2]?pic|:.orderCol           SELECT with ORDER BY orderCol DESC
     * @example user[id=12347]?pic=new.jpg              UPDATE
     * @example user[pic='new.jpg',name='dec A. orz']>  INSERT
     * @example user[nm='alx']:d                        DELETE
     * @example user[staff.salary>10]?name              SELECT with RELATIONS (staff -- another table)
     * @example page[product.nm=* ref:artcl_prod]?descr,url|:.created           SELECT with RELATIONS (Является временным синтаксисом)
     * @example See another emamples
     *          $mq->newR("page[product.nm=* ref:artclPage_prod__proxy]?descr,url,title,img|:.created", 's', $prod, Mq_Mode::NOSMART_ENDDATA);
     *          $reachProdsInfo = $mq->r("product[ref:prodMainPage ref:theme]?count(video)>`all`, product.videoShwIndex>`curr`, page.url, product.nm, product.shortName| GROUP BY product.id", true, false);
     *          $poorProdsInfo = $mq->r("product[ref:prodMainPage product.videoShwIndex=-1]?0>`all`, 0>`curr`, page.url, product.nm, product.shortName", true, false);
     *          5.2 Note: insert notation changed!
     *
     * @param $reqLine string alx-нотация запроса к SQL-БД
     * @param bool $isLoggingRequire
     *
     * @return string
     */
private function parseAlxMqSyntax($reqLine, $isLoggingRequire = false)
{
    // Declaration list. *NOT TO DELETE*
    //
    // $part = array();
    // $part2ToJoinTables = array(); // Addition tables
    // $part1 = ''; // FROM
    // $part2ToJoinTables_cnt = 0; // Count of Addition tables
    if(!$isLoggingRequire)$isLoggingRequire = $this->isLoggingRequire;
    $limit = $out = $orderOptionStr = '';

    $reqArr = preg_split('!;\s*!', $reqLine);//                                                                Разбивка на отдельные запросы, разделённые символом ';'
    foreach ($reqArr as $req) { // Проход по отдельным alx-запросам
        if (preg_match('!:d$!', $req)) {//                                                                              * Требуется запрос удаления
            preg_match('!^([a-z_]+)\[(.*)\]:d$!i', $req, $part);//                                           Разбить alx-запрос на простые составляющие (для запроса удаления)

            return "DELETE FROM $part[1] WHERE $part[2];";
        }
        if (preg_match('!^[a-z_]+\[.*\]>$!i', $req)) {//                                                                * Требуется запрос вставки
            preg_match('!^([a-z_]+)\[(.*)\]>\s*$!i', $req, $part);//                                           Разбить alx-запрос на простые составляющие (для запроса вставки)
            $table = $part[1];
            $part[2] = str_replace('*', '?', $part[2]);
            $insStr = $part[2];
            return "INSERT INTO $table SET"." $insStr";
        }
        preg_match('/^([a-z_]+)\[(.*)\]\?([a-z0-9_,`\.\'=*? >\(\)]+)(\|(?:(\.:|:\.)\s*([\S]*))?)?(?:\s+(.*))?\s*$/i', $req, $part);//           Разбить alx-запрос на составляющие
        /*
         * [1]--primary_table [2]--condition [3]--aim [4: [5]--.:|:.--order_dir [6]--order_col [7]--group]
         */

        $part[2] = isset($part[2]) ? str_replace('*', '?', $part[2]) : '';
        $part[3] = isset($part[3]) ? str_replace('>', ' AS ', $part[3]) : '';
        preg_match_all('!ref:([a-z_]+)!im', $part[2].',', $additionRefTables, PREG_PATTERN_ORDER);
        $part[2] = preg_replace('!ref:([a-z_]+)!im',"", $part[2]);
        $part[2] = str_replace('&&', ' AND ', $part[2]);

        $part[1] = $part1 = isset($part[1]) ? $part[1] : '';

        preg_match_all('!([a-z_]+)\.!i', $part[2], $part2ToJoinTables1, PREG_PATTERN_ORDER);
        preg_match_all('!([a-z_]+)\.|count\s*\(([a-z_]+)\)!i', $part[3], $part2ToJoinTables2, PREG_PATTERN_ORDER);

        $part2ToJoinTables = array_merge( $part2ToJoinTables1[1], $part2ToJoinTables2[1], $part2ToJoinTables2[2], $additionRefTables[1]);
        $part2ToJoinTables = array_unique(array_filter($part2ToJoinTables,function($el){
            if (trim($el)=='') return false;
            return true;
        }));

        $part2ToJoinTables_cnt = count($part2ToJoinTables);

        if ($part2ToJoinTables_cnt) {//                                                                                 * Требуется мультитабличная предобработка

            // Check all possible refs from information_schema
            // and join all ref-tables
            if ($isLoggingRequire) $this->messageLog("[DEBUG LOG] Mq::parseAlxMqSyntax(): MultiTablesReq");
            foreach ($part2ToJoinTables as $table) {
                if ($part[1] != $table) $part1 .= ' JOIN ' . $table;
            }
            $part1 .= ' ON ';
            $part1_arr = array();
            $k = 0;
            $information_schema = new Mq("information_schema");
            $infoStmt = $information_schema->newR("COLUMNS[TABLE_SCHEMA=[SCHEME_NAME_DEFAULT] && TABLE_NAME=*]?COLUMN_NAME", '', false, Mq_Mode::NOSMART_STMT);
            array_unshift($part2ToJoinTables, $part[1]);
            foreach ($part2ToJoinTables as $table1)
                foreach ($part2ToJoinTables as $table2){
                    if ($table1 == $table2) continue;
                    $infoStmt->bind_param('s',$table2); $infoStmt->execute(); $infoResult = $infoStmt->get_result(); // Параметризуем, выполняем, получаем результаты
                    for ($i = 0; $tmpResult = $infoResult->fetch_array(MYSQLI_ASSOC); $i++) {
                        if (preg_match("!${table1}_id!", $tmpResult["COLUMN_NAME"])){
                            $part1_arr[$k++] = "$table2.${table1}_id=$table1.id";
                            break;
                        }
                    }
                }
            $part1 .= join(" AND ", $part1_arr);

            /*
             * Подстановка в каждое запрашиваемое поле(не имеющее указания таблицы) указания основной (первичной) таблицы ([1]--primary_table)
             */
            $part3_parts = preg_split('!,\s*!', $part[3]);
            $part3_parts = preg_replace_callback(
                '!^.+$!m',
                function ($matches) use ($part){
                    if ( preg_match('!^[^0-9][^\.]+$!i',$matches[0]) && !preg_match('!^.*count.*$!i',$matches[0]) )
                        return $part[1] . '.' . $matches[0];
                    else return $matches[0];
                },
                $part3_parts
            );
            $part[3] = join(",   ",$part3_parts);
            if (isset($part[5]) && $part[5]!='') $part[6] = $part[1] . '.' . ($part[6]==''?'id':$part[6]);
        } // if ($part2ToJoinTables_cnt) мультитабличная предобработка

        $part[3] = preg_replace('!COUNT\s*([^\(]|$)!i', "COUNT($part[1].id)$1", $part[3]);//                     Замена COUNT на COUNT(PrimaryTable.id)
        $part[3] = preg_replace('!COUNT\s*\(([^\.]*?)\)!i', "COUNT($1.id)", $part[3]);  //                     Замена COUNT(SomeTable) на COUNT(SomeTable.id)
	if (!isset($part[7])) $part[7] = "";
        if (trim($part[7])) if (preg_match('/\blimit\b/',$part[7])) {$limit = " $part[7] ";$part[7]='';} else $part[7] = " GROUP BY $part[7] ";
        $part2 = (trim($part[2])=="" ? "" : " WHERE $part[2]");
        if (strpos($part[3], '=') === false)//                                                                          * Требуется запрос выборки
        {
            if (preg_match('!(?:^|\s+)id\s*=!', $part[1]))//                                                 Необходима одна запись
                $limit = ' LIMIT 1';
            if (isset($part[5]) && $part[5]!='')//                                                             Обнаружено правило сортировки
                $orderOptionStr = 'ORDER BY ' . $part[6] . ' ' . ($part[5] == ':.' ? ' DESC ' : ' ASC ');
            $out .= "SELECT $part[3] FROM $part1 $part2 $part[7] $orderOptionStr". $limit;
        } else {//                                                                                                      * Требуется запрос обновления
            $part[3] = str_replace('*', '?', $part[3]);
            $out .= "UPDATE $part[1] SET " . $part[3] . "$part2;";
        }
    } // foreach ($reqArr as $req) Проход по отдельным alx-запросам
    if ($isLoggingRequire)
        $this->messageLog("[DEBUG LOG] Mq::parseAlxMqSyntax(): raw-in: $reqLine Alx-out: '$out'");

    return $out;
} // function parseAlxMqSyntax()



    /**
     * Занести в лог $dbgMsg + дополнительные параметры, если зафиксирована ошибка
     * @param string $errMsg
     */
    private function errorCheck($errMsg = '')
    {
        $this->errorLog($errMsg, false);
    }

    /**
     * Занести в лог $dbgMsg + дополнительные параметры(если есть) БЕЗУСОЛВНО
     * @param $errMsg string
     * @param $force bool
     * @return string
     */
    private function errorLog($errMsg = '', $force = true)
    {
        $driverErr = $this->checkDriverError();
        if ($force || $driverErr) bugReport("$driverErr ($errMsg)", $this->x); // сделать запись если произошли ошибки или если $force=true (даже, если ошибок не было или если они были)
        return $driverErr;
    }

    /**
     * Занести в ИНФОРМАЦИОННЫЙ лог $dbgMsg + дополнительные параметры(если есть) БЕЗУСОЛВНО
     * @param string $dbgMsg
     */
    private function messageLog($dbgMsg = '')
    {
        if (!$this->errorLog($dbgMsg)) // Действительно сделать запись в ИНФО лог, но только если не было ошибок
            infoReport('[DEBUG_LOG] ' . $dbgMsg, $this->x);
    }

    /**
     * Check mysqli driver last errors
     * @return bool|string
     */
    private function checkDriverError()
    {
        $additionErrorType = 'unknown';
        $errNo = 0;
        $errNote = '';
        if ($this->stmt && $this->stmt->errno) {
            $errNo = $this->stmt->errno;
            $errNote = $this->stmt->error;
            $additionErrorType = 'stmt';
        } elseif ($this->hndl->errno) {
            $errNo = $this->hndl->errno;
            $errNote = $this->hndl->error;
        } elseif ($this->hndl->connect_errno) {
            $errNo = $this->hndl->connect_errno;
            $errNote = $this->hndl->connect_error;
            $additionErrorType = 'connect';
        }
        return $errNo ? "[ERR_LOG] Mq $additionErrorType error #$errNo '$errNote'" : false;
    }



/**
 * @param mysqli_stmt $stmt
 * @return array
 */
function get_result($stmt)
{
    $tmp = array();
    $special_result = $stmt->result_metadata();
    $field_count = $special_result->field_count;
    for ($i = 0; $i < $field_count; ++$i) {
        $tmp[$i] = null;
        $stmt->bind_result($tmp[$i]);
    }
    return $tmp;
}

function close()
{
    $this->hndl->close();
}

function  __destruct()
{
    $this->hndl->close();
}

/**
 * mysqli::real_escape_string (mysqli_real_escape_string)
 * Экранирует специальные символы в строке для использования в SQL выражении,
 * используя текущий набор символов соединения
 *
 * @param $str
 * @return string
 */
function esc($str)
{
    return $this->hndl->real_escape_string($str);
}
}


// TODO ORM <a>http://ru.wikipedia.org/wiki/ORM</a> Doctrine ORM <a>http://docs.doctrine-project.org/projects/doctrine-orm/en/2.1/tutorials/getting-started-xml-edition.html</a>
