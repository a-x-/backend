<?php
/**
 * ** Goal: ИМПОРТИРОВАНО ИЗ SP 21-го МАРТА 2013-го **
 * ** Sp: ИМПОРТИРОВАНО ИЗ Goal 23-го августа 2013-го **
 * ** COMMON: ИМПОРТИРОВАНО ИЗ SP 26-го МАЯ 2014-го **
 *
 * Created with PhpStorm.
 * @author Inventorem
 * @time   22.12.12 / 14:00
 *
 * It uses mysqli driver
 */

require_once 'common.php';
require_once "$_SERVER[DOCUMENT_ROOT]/_data/consts.php";

/**
 * Class Mq_Mode is  class Mq internal ENUM
 */
class Mq_Mode
{
    const SMART_DATA          = 1;
    const RAW_DATA            = 2;
    const ITERATIVE_RESULT    = 3;
    const PREPARED_QUERY_STMT = 4;
}


/**
 * @version 6.0.1
 * 5.2 Note: insert notation changed!
 * 5.3 Note: update behaviour changed // params order is true now.
 * 5.4 Note q, qq, r are removed
 * 5.5 Note newQ, newQQ renamed end refactored into q1, q2, q3, q4
 *     Note req and parseAlxMqSyntax moved out to AlxMq class
 * 6.0 Note req() method moved out to AlxMq child class
 */
class Mq
{
    protected $driver;
    protected $isLoggingRequire;
    protected $stmt;
    protected $schemeName;


    /**
     * Construct
     *
     * @param string $schemeName    имя "базы данных" (способствует использованию нескольких "баз данных"[в рамках терминов mySQL])
     * @param bool   $isLoggingNeed выбрасавыть на клиента все логи
     */
    public function __construct($schemeName = '', $isLoggingNeed = false)
    {
        if (is_array($schemeName)) {
            $opts          = $schemeName;
            $schemeName    = @$opts['schemeName'];
            $isLoggingNeed = @$opts['isLog'];
        }
        $this->isLoggingRequire = $isLoggingNeed;
        $this->schemeName       = $schemeName;
        if (!$schemeName) $schemeName = SCHEME_NAME_DEFAULT; // По умолчанию берётся из settings.ini
        $this->driver = new mysqli(); // Параметры устанавливаются из php-conf
        $this->driver->real_connect();
        $this->driver->select_db($schemeName);

        $this->errorCheck('Mq::Mq initialization not success! {' . (__FILE__ . ':' . __LINE__ . ' ' . __FUNCTION__ . '()') . '}');

        /* MANUAL
         * Очень важно выставление правильной кодировки
         * В настройках mySQL-сервера (my.conf) или php-командой из mySQL API:
         * $this->driver->real_query('set names utf8');
         */
    }

    /**
     * Занести в лог $dbgMsg + дополнительные параметры, если зафиксирована ошибка
     *
     * @param string $errMsg
     */
    protected function errorCheck($errMsg = '')
    {
        $this->errorLog($errMsg, false);
    }

    /**
     * Занести в лог $dbgMsg + дополнительные параметры(если есть) БЕЗУСОЛВНО
     *
     * @param $errMsg string
     * @param $force  bool
     *
     * @return string
     */
    protected function errorLog($errMsg = '', $force = true)
    {
        $driverErr = $this->checkDriverError();
        if ($force || $driverErr) \Invntrm\bugReport2('Mq', "$driverErr ($errMsg)"); // сделать запись если произошли ошибки или если $force=true (даже, если ошибок не было или если они были)
        return $driverErr;
    }

    /**
     * Check mysqli driver last errors
     * @return bool|string
     */
    protected function checkDriverError()
    {
        $additionErrorType = 'unknown';
        $errNo             = 0;
        $errNote           = '';
        if ($this->stmt && $this->stmt->errno) {
            $errNo             = $this->stmt->errno;
            $errNote           = $this->stmt->error;
            $additionErrorType = 'stmt';
        } elseif ($this->driver->errno) {
            $errNo   = $this->driver->errno;
            $errNote = $this->driver->error;
        } elseif ($this->driver->connect_errno) {
            $errNo             = $this->driver->connect_errno;
            $errNote           = $this->driver->connect_error;
            $additionErrorType = 'connect';
        }
        return $errNo ? "[ERR_LOG] Mq $additionErrorType error #$errNo '$errNote'" : false;
    }

    /**
     * Get OPTIMISED (ROWS LIST OR ROW ID /if row inserted/).
     *
     * @param        $req
     * @param string $sigma
     * @param array  $params
     * @param bool   $isLoggingRequire
     *
     * @return array|bool|mixed
     */
    public function q4($req, $sigma = "", $params = array(), $isLoggingRequire = false)
    {
        $raw   = $this->q3($req, $sigma, $params, $isLoggingRequire);
        $smart = $this->fromRawToSmart($raw);
        return $smart;
    }

    /**
     * Get RAW ROWS LIST
     *
     * @param        $req
     * @param string $sigma
     * @param array  $params
     * @param bool   $isLoggingRequire
     *
     * @return array|bool
     */
    public function q3($req, $sigma = "", $params = array(), $isLoggingRequire = false)
    {
        $isLoggingRequire = $isLoggingRequire || $this->isLoggingRequire;
        $iterative        = $this->q2($req, $sigma, $params);
        $raw              = $this->fromIterativeToRaw($iterative, $req);
        if ($isLoggingRequire) $this->messageLog("<b>sqlResult: </b>" . \Invntrm\varDumpRet($raw));
        //
        return $raw;
    }


    /**
     * Get RAW RESULT.
     *
     * @param      $req             string Запрос-заготовка с плейсхолдерами
     * @param      $sigma           string Строка кодов типов параметров
     * @param      $params          array Параметры
     * @param bool $isLoggingRequire
     *
     * @return mysqli_result|bool -- Ресурс-класс, позволяющий совершать итерации fetch снаружи
     */
    public function q2($req, $sigma = "", $params = array(), $isLoggingRequire = false)
    {
        $isLoggingRequire = $isLoggingRequire || $this->isLoggingRequire;
        $stmt             = $this->q1($req, $isLoggingRequire);
        if ($isLoggingRequire) $this->messageLog("<b>REQ Q2</b>$req; <b>Параметры</b><br>" . \Invntrm\varDumpRet($params));
        //
        //
        $result = $this->fromStmtToIterative($stmt, $sigma, $params);
        return $result;
    }

    /**
     * Get STMT
     *
     * @param        $req
     * @param bool   $isLoggingRequire
     *
     * @internal param string $sigma
     * @internal param array $params
     * @return bool|mysqli_stmt - Ресурс драйвера, позволяющий повторять приготовленныей запрос с параметрами
     */
    public function q1($req, $isLoggingRequire = false)
    {
        $isLoggingRequire = $isLoggingRequire || $this->isLoggingRequire;
        if ($isLoggingRequire) $this->messageLog("<b>REQ Q1: </b>$req; ");
        $stmt = $this->fromReqToStmt($req);
        if (!$stmt) {
            $this->errorLog('mq class: $stmt don`t calculated! Req:' . \Invntrm\varDumpRet($req));
            return false;
        }
        return $stmt;
    }

    /**
     * Занести в ИНФОРМАЦИОННЫЙ лог $dbgMsg + дополнительные параметры(если есть) БЕЗУСОЛВНО
     *
     * @param string $dbgMsg
     */
    protected function messageLog($dbgMsg = '')
    {
        if (!$this->errorLog($dbgMsg)) // Действительно сделать запись в ИНФО лог, но только если не было ошибок
            \Invntrm\_d('[DEBUG_LOG] ' . $dbgMsg);
    }

    public function fromReqToStmt($req)
    {
        $req        = $this->reqPreprocessor($req);
        $this->stmt = $stmt = $this->driver->prepare($req);
        return $stmt;
    }

    protected function reqPreprocessor($req)
    {
        $req = preg_replace_callback('/^([a-z\.\-_]+)\.sql$/i', function ($matches) {
            return file_get_contents("sql-reqs/$matches[1].sql");
        }, $req);
        $req = preg_replace('!\[(?:SCHEME_NAME|TABLE_SCHEMA)\]!', '"' . $this->schemeName . '"', $req); // Название "базы данных" (в терминах mySQL)
        $req = preg_replace('!\[SCHEME_NAME_DEFAULT\]!', '"' . SCHEME_NAME_DEFAULT . '"', $req); // Название "базы данных" (в терминах mySQL)
        return $req;
    }

    public function fromStmtToIterative($stmt, $sigma, $params)
    {
        $result = \Mq::fromStmt($stmt, $sigma, $params, Mq_Mode::ITERATIVE_RESULT);
        if (!$result->num_rows) $this->messageLog('result is empty!');
        return $result;
    }

    /**
     * STMT (prepared Query) -> ITERATIVE DATA
     *
     * @param            $stmt mysqli_stmt
     * @param            $sigma
     * @param            $params
     * @param int        $mode
     * @param string     $req  Fill if $mode == Mq_Mode::SMART_DATA
     *
     * @internal param \Mq|string $self__schemeName
     * @return mixed
     */
    public function fromStmt($stmt, $sigma = [], $params = [], $mode = Mq_Mode::SMART_DATA, $req = '')
    {
        if (!is_array($params)) $params = array($params);
        if (strlen($sigma) != \Invntrm\true_count($params))
            $this->errorLog("'$sigma' !~ " . \Invntrm\varDumpRet($params) . '"');
        if ($sigma) {
            if (\Invntrm\true_count($params)) {
                array_unshift($params, $sigma); // Расширяем начальным элементом, содержащим сигнатуру
                $tmp = array(); // Преобразуем строки в ссылки (требуется функции call_user_func_array)
                foreach ($params as $key => $value) $tmp[$key] = & $params[$key]; // ...
                call_user_func_array(array($stmt, 'bind_param'), $tmp); // Запускаем $stmt->bind_param с праметрами из массива
            }
        }
        $stmt->execute();
        $result = $stmt->get_result(); // Выполняем, получаем результаты
        if ($mode == Mq_Mode::ITERATIVE_RESULT) {
            return $result;
        }
        if ($mode >= Mq_Mode::RAW_DATA) {
            $result = $this->fromStmtToIterative($stmt, $sigma, $params);

            if ($mode >= Mq_Mode::SMART_DATA) {
                $result = $this->fromIterativeToRaw($result, $req);
            }
        }
        return $result;
    }

    /**
     * @param $iterative mysqli_result
     * @param $req
     *
     * @return bool|mixed
     */
    public function fromIterativeToRaw($iterative, $req)
    {
        $row = [];
        if (preg_match("!^INSERT!", $req))
            return $this->driver->insert_id; // При таких запросах единственное, что мб интересно: id записи
        elseif (preg_match("!^UPDATE!", $req))
            return preg_replace("!.*WHERE.*`?id`?=([0-9+]).*!", "$1", $req); // При таких запросах единственное, что мб интересно: id записи
        if (!$iterative)
            return false;
        //
        while ($tmp = $iterative->fetch_array(MYSQLI_ASSOC)) $row[] = $tmp;
        $raw = $row;
        return $raw;
    }

    public function fromRawToSmart($raw)
    {
        $smart = \Invntrm\recursiveDegenerateArrOptimize($raw);
        return $smart;
    }

    /**
     * @todo what is it
     *
     * @param mysqli_stmt $stmt
     *
     * @return array
     */
    function get_result($stmt)
    {
        $tmp            = array();
        $special_result = $stmt->result_metadata();
        $field_count    = $special_result->field_count;
        for ($i = 0; $i < $field_count; ++$i) {
            $tmp[$i] = null;
            $stmt->bind_result($tmp[$i]);
        }
        return $tmp;
    }

    public function close()
    {
        $this->driver->close();
    }

    public function  __destruct()
    {
        $this->driver->close();
    }

    /**
     * mysqli::real_escape_string (mysqli_real_escape_string)
     * Экранирует специальные символы в строке для использования в SQL выражении,
     * используя текущий набор символов соединения
     *
     * @param $str
     *
     * @return string
     */
    public function esc($str)
    {
        return $this->driver->real_escape_string($str);
    }
}

/**
 * Class AlxMq
 * @version 6.0
 */
class AlxMq extends Mq
{
    protected $isLoggingRequire;

    public function __construct($schemeName = '', $isLoggingNeed = false)
    {
        \Mq::__construct($schemeName, $isLoggingNeed);
    }

    /**
     * Разбор запросов упрощенной нотации доступа к SQL-базам данных
     *
     * * Может работать с рядом запросов, разделённых символами ;\s*
     * Наличие полей в обрабатываемых таблицах проверяется, если это необходимо (необходимо наличие information_schema)
     * * Добавление |.: (ASC) или |:. (DESC) в конец позволяет отсортировать результаты SELECT-запроса
     *
     * @TODO    внедрить пересечения по свойству (пересечение классов, при котором один из классов выступает задаваемым свойством другого) // page[@prod=main] page, являющаяся main для prod
     * @TODO    кстати сделать автоматическое формирование умной структуры данных для конструкции пересечения по свойству {page1:{prop1,,,prod:{prep1,,,}},,,}
     *
     * @TODO    внедрить следование (один класс выступает родительским для другого, при этом для их связывания испрользуется поле дочернего класса родКласс_id) // prod \\ theme \\ video
     * @TODO    кстати сделать автоматическое формирование умной структуры данных для конструкции следования {prod1:{theme1:{videoName1:{prop1,,,},,,},,,},,,}
     *
     * @TODO    если у класса определено поле (поле в таблице бд) name использовать его для формирования ассоциативного массива-результата вместо обычного упорядоченного перечисления
     *
     * @example user[namm='alx']?pic                    SELECT
     * @example user['alx']?pic                         SELECT (same)
     * @example user[n=1&&L=2]?pic|:.orderCol           SELECT with ORDER BY orderCol DESC
     * @example user[id=12347]?pic=new.jpg              UPDATE
     * @example user[12347]?pic=new.jpg                 UPDATE (same)
     * @example user[pic='new.jpg',name='dec A. orz']>  INSERT
     * @example user[nm='alx']:d                        DELETE
     * @example user[staff.salary>10]?name              SELECT with RELATIONS (staff -- another table)
     * @example page[product.nm=* ref:artcl_prod]?descr,url|:.created           SELECT with RELATIONS (Является временным синтаксисом)
     * @example See another examples
     *          $mq->newR("page[product.nm=* ref:artclPage_prod__proxy]?descr,url,title,img|:.created", 's', $prod, Mq_Mode::RAW_DATA);
     *          $reachProdsInfo = $mq->r("product[ref:prodMainPage ref:theme]?count(video)>`all`, product.videoShwIndex>`curr`, page.url, product.nm, product.shortName| GROUP BY product.id", true, false);
     *          $poorProdsInfo = $mq->r("product[ref:prodMainPage product.videoShwIndex=-1]?0>`all`, 0>`curr`, page.url, product.nm, product.shortName", true, false);
     *          5.2 Note: insert notation changed!
     *
     * @param      $reqLine string alx-нотация запроса к SQL-БД
     * @param      $sigma
     * @param      $params
     * @param bool $isLoggingRequire
     *
     * @return string
     */
    protected function parseAlxMqSyntax($reqLine, &$sigma, &$params, $isLoggingRequire = false)
    {
        // Declaration list. *NOT TO DELETE*
        //
        // $part = array();
        // $part2ToJoinTables = array(); // Addition tables
        // $part1 = ''; // FROM
        // $part2ToJoinTables_cnt = 0; // Count of Addition tables
        $part2Preprocessor = function ($part2) {
            $part2 = trim($part2);
            $part2 = str_replace('*', '?', $part2);
            //
            // @example If part2='blue-hamster'
            // @example If part2="heo_fast_quota"
            if (preg_match('!^(\'|").*\1$!', $part2)) {
                $part2 = preg_replace('!^(?:\'|")|(?:\'|")$!', '', $part2); // delete string parenthesises for normalize
                $part2 = "name='$part2'";
            }
            //
            // @example If $part2=3232
            // @example If $part2=42
            if (preg_match('!^\d+$!', $part2)) {
                $part2 = "id=$part2";
            }
            return $part2;
        };
        if (!$isLoggingRequire) $isLoggingRequire = $this->isLoggingRequire;
        $limit = $out = $orderOptionStr = '';

        $reqArr = preg_split('!;\s*!', $reqLine); //                                                                Разбивка на отдельные запросы, разделённые символом ';'
        foreach ($reqArr as $req) { // Проход по отдельным alx-запросам
            if (preg_match('!:d$!', $req)) { //                                                                              * Требуется запрос удаления
                preg_match('!^([a-z_]+)\[\s*(.*)\s*\]:d$!i', $req, $part); //                                           Разбить alx-запрос на простые составляющие (для запроса удаления)

                return "DELETE FROM $part[1] WHERE $part[2];";
            }
            if (preg_match('!^[a-z_]+\[.*\]>$!i', $req)) { //                                                                * Требуется запрос вставки
                preg_match('!^([a-z_]+)\[(.*)\]>\s*$!i', $req, $part); //                                           Разбить alx-запрос на простые составляющие (для запроса вставки)
                $table   = $part[1];
                $part[2] = $part2Preprocessor($part[2]);
                return "INSERT INTO $table SET $part[2]";
            }
            preg_match('/^([a-z_]+)\[\s*(.*)\s*\]\?([a-z0-9_,`\.\'=*? >\(\)]+)(\|(?:(\.:|:\.)\s*([\S]*))?)?(?:\s+(.*))?\s*$/i', $req, $part); //           Разбить alx-запрос на составляющие
            /*
             * [1]--primary_table [2]--condition [3]--aim [4: [5]--.:|:.--order_dir [6]--order_col [7]--group]
             */

            $part[2] = $part2Preprocessor(@$part[2]);
            $part[3] = isset($part[3]) ? str_replace('>', ' AS ', $part[3]) : '';
            preg_match_all('!ref:([a-z_]+)!im', $part[2] . ',', $additionRefTables, PREG_PATTERN_ORDER);
            $part[2] = preg_replace('!ref:([a-z_]+)!im', "", $part[2]);
            $part[2] = str_replace('&&', ' AND ', $part[2]);

            $part[1] = $part1 = isset($part[1]) ? $part[1] : '';

            preg_match_all('!([a-z_]+)\.!i', $part[2], $part2ToJoinTables1, PREG_PATTERN_ORDER);
            preg_match_all('!([a-z_]+)\.|count\s*\(([a-z_]+)\)!i', $part[3], $part2ToJoinTables2, PREG_PATTERN_ORDER);

            $part2ToJoinTables = array_merge($part2ToJoinTables1[1], $part2ToJoinTables2[1], $part2ToJoinTables2[2], $additionRefTables[1]);
            $part2ToJoinTables = array_unique(array_filter($part2ToJoinTables, function ($el) {
                if (trim($el) == '') return false;
                return true;
            }));

            $part2ToJoinTables_cnt = \Invntrm\true_count($part2ToJoinTables);

            //
            //
            // Требуется мультитабличная предобработка
            if ($part2ToJoinTables_cnt) {
                //
                // Check all possible refs from information_schema
                // and join all ref-tables
                if ($isLoggingRequire) $this->messageLog("[DEBUG LOG] Mq::parseAlxMqSyntax(): MultiTablesReq");
                foreach ($part2ToJoinTables as $table) {
                    if ($part[1] != $table) $part1 .= ' JOIN ' . $table;
                }
                $part1 .= ' ON ';
                $part1_arr          = array();
                $k                  = 0;
                $information_schema = new AlxMq("information_schema");
                $infoStmt           = $information_schema->req("COLUMNS[TABLE_SCHEMA=[SCHEME_NAME_DEFAULT] && TABLE_NAME=*]?COLUMN_NAME", '', false, Mq_Mode::PREPARED_QUERY_STMT);
                array_unshift($part2ToJoinTables, $part[1]);
                foreach ($part2ToJoinTables as $table1)
                    foreach ($part2ToJoinTables as $table2) {
                        if ($table1 == $table2) continue;
                        $infoStmt->bind_param('s', $table2);
                        $infoStmt->execute();
                        $infoResult = $infoStmt->get_result(); // Параметризуем, выполняем, получаем результаты
                        for ($i = 0; $tmpResult = $infoResult->fetch_array(MYSQLI_ASSOC); $i++) {
                            if (preg_match("!${table1}_id!", $tmpResult["COLUMN_NAME"])) {
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
                    function ($matches) use ($part) {
                        if (preg_match('!^[^0-9][^\.]+$!i', $matches[0]) && !preg_match('!^.*count.*$!i', $matches[0]))
                            return $part[1] . '.' . $matches[0];
                        else return $matches[0];
                    },
                    $part3_parts
                );
                $part[3]     = join(",   ", $part3_parts);
                if (isset($part[5]) && $part[5] != '') $part[6] = $part[1] . '.' . ($part[6] == '' ? 'id' : $part[6]);
            } // if ($part2ToJoinTables_cnt) мультитабличная предобработка

            $part[3] = preg_replace('!COUNT\s*([^\(]|$)!i', "COUNT($part[1].id)$1", $part[3]); //  Замена COUNT на COUNT(PrimaryTable.id)
            $part[3] = preg_replace('!COUNT\s*\(([^\.]*?)\)!i', "COUNT($1.id)", $part[3]); //      Замена COUNT(SomeTable) на COUNT(SomeTable.id)
            if (!isset($part[7])) $part[7] = "";
            if (trim($part[7])) if (preg_match('/\blimit\b/', $part[7])) {
                $limit   = " $part[7] ";
                $part[7] = '';
            } else $part[7] = " GROUP BY $part[7] ";
            $part2 = (trim($part[2]) == "" ? "" : " WHERE $part[2]");
            //
            // request
            // SELECT
            if (strpos($part[3], '=') === false) {
                if (preg_match('!(?:^|\s+)id\s*=!', $part[1])) // Необходима одна запись
                    $limit = ' LIMIT 1';
                if (isset($part[5]) && $part[5] != '') // Обнаружено правило сортировки
                    $orderOptionStr = 'ORDER BY ' . $part[6] . ' ' . ($part[5] == ':.' ? ' DESC ' : ' ASC ');
                $out .= "SELECT $part[3] FROM $part1 $part2 $part[7] $orderOptionStr" . $limit;
            }
            //
            // request
            // UPDATE
            else {
                $part[3] = str_replace('*', '?', $part[3]);
                $out .= "UPDATE $part[1] SET " . $part[3] . "$part2;";
                if (\Invntrm\true_count($params)) {
                    //
                    // fix params order
                    $whereExpr = array_shift($params);
                    $params[]  = $whereExpr;
                    //
                    // fix sigma order
                    $whereExprSign = substr($sigma, 0, 1);
                    $sigma         = substr($sigma, 1, strlen($sigma) - 1);
                    $sigma .= $whereExprSign;
                }
            }
        } // foreach ($reqArr as $req) Проход по отдельным alx-запросам
        if ($isLoggingRequire)
            $this->messageLog("[DEBUG LOG] Mq::parseAlxMqSyntax(): raw-in: $reqLine Alx-out: '$out'");

        return $out;
    } // function parseAlxMqSyntax()

    /**
     * Может работать с рядом запросов, разделённых символами !;\s*!
     * См. Mq::parseAlxMqSyntax() for $req syntax
     *
     * @param string       $req
     * @param string       $sigma При выполнении серии запросов нумеруется сквозным образом
     * @param array|bool   $params
     * @param int|\Mq_Mode $mode
     * @param bool         $isLoggingRequire
     *
     * @return string|array|bool|mysqli_result|mysqli_stmt mysqli_result
     */
    public function req($req, $sigma = '', $params = false, $mode = Mq_Mode::SMART_DATA, $isLoggingRequire = false)
    {
        $req = $this->parseAlxMqSyntax($req, $sigma, $params, $isLoggingRequire);
        //
        if ($mode == Mq_Mode::SMART_DATA) {
            return $this->q4($req, $sigma, $params, $isLoggingRequire);
        } elseif ($mode == Mq_Mode::RAW_DATA) {
            return $this->q3($req, $sigma, $params, $isLoggingRequire);
        } elseif ($mode == Mq_Mode::ITERATIVE_RESULT) {
            return $this->q2($req, $sigma, $params, $isLoggingRequire);
        } elseif ($mode == Mq_Mode::PREPARED_QUERY_STMT) {
            return $this->q1($req, $isLoggingRequire);
        } else
            return false;
    }
}


// TODO ORM <a>http://ru.wikipedia.org/wiki/ORM</a> Doctrine ORM <a>http://docs.doctrine-project.org/projects/doctrine-orm/en/2.1/tutorials/getting-started-xml-edition.html</a>

