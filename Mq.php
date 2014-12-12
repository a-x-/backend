<?php
/**
 * mysqli wrapper (named Mq by me) and stupid ORM (named AlxMq).
 *
 * ** «Goal»: IMPORTED    AT 2013, March,  21 **
 * ** «Sp»: IMPORTED      AT 2013, August, 23 **
 * ** «COMMON»: IMPORTED  AT 2014, May,    26 **
 *
 */

require_once __DIR__ . '/../../../_ass/common.php';
require_once __DIR__ . "/../../../_data/consts.php";

/**
 * Class Mq_Mode is  class Mq internal ENUM
 */
class Mq_Mode
{
    const SMART_DATA       = 4;
    const RAW_DATA         = 3;
    const ITERATIVE_RESULT = 2;
    const PREPARED_STMT    = 1;
    const REQUEST          = 0;
}


/**
 * @version 8.0.0
 * 5.2 Note: insert notation changed!
 * 5.3 Note: update behaviour changed // params order is true now.
 * 5.4 Note q, qq, r are removed
 * 5.5 Note newQ, newQQ renamed end refactored into q1, q2, q3, q4
 *     Note req and parseAlxMqSyntax moved out to AlxMq class
 * 6.0 Note req() method moved out to AlxMq child class
 * 7.0 Note replace chaotic `false` returns by throw Exceptions
 * 8.0 AlxMq syntax no-backward compatible changed
 *
 */
class Mq
{
    /**
     * @var mysqli
     */
    protected $driver;
    protected $stmt;
    protected $isLog = false;
    protected $schemeName = '';
    protected $chainMethod = ['fromReqToStmt', 'fromStmtToIterative', 'fromIterativeToRaw', 'fromRawToSmart'];
    protected $req = '';


    /**
     * Construct
     *
     * @param string $schemeName имя "базы данных"
     * @param bool   $isLog      Логировать действия
     *
     * @throws MqException
     */
    public function __construct($schemeName = '', $isLog = false)
    {
        if (!is_string($schemeName) && !is_array($schemeName)) {
            $isLog      = $schemeName;
            $schemeName = '';
        }
        if (is_array($schemeName)) {
            $args       = $schemeName;
            $schemeName = \Invntrm\true_get($args, 'schemeName');
            $isLog      = \Invntrm\true_get($args, 'isLog');
        }
        else {
            $args = ['schemeName' => $schemeName, 'isLog' => $isLog];
        }
        $this->isLog      = $isLog;
        $this->schemeName = $schemeName;
        if (!$schemeName) $schemeName = SCHEME_NAME_DEFAULT; // По умолчанию берётся из файла конфигурации
//        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->driver = new mysqli(); // Параметры устанавливаются из php-conf
//        $this->driver->report_mode(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
//        $this->driver->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
        $this->driver->real_connect();
        if ($error = $this->getCheckDriverError()) {
            throw new \MqException('Initialization not success', $args, $error);
        }
        $isSelectDbSuccess = $this->driver->select_db($schemeName);
        if (!$isSelectDbSuccess)
            throw new \MqException('Db not found!', $args, $error);
        /* MANUAL
         * Очень важно выставление правильной кодировки
         * В настройках mySQL-сервера (my.conf) или php-командой из mySQL API:
         * $this->driver->real_query('set names utf8');
         */
    }

    /**
     * Check mysqli driver last errors
     *
     * @return bool|string
     */
    protected function getCheckDriverError()
    {
        $additionErrorType = 'unknown';
        $errNo             = 0;
        $errNote           = '';
        if ($this->stmt && $this->stmt->errno) {
            $errNo             = $this->stmt->errno;
            $errNote           = $this->stmt->error;
            $additionErrorType = 'stmt';
        }
        elseif ($this->driver->errno) {
            $errNo   = $this->driver->errno;
            $errNote = $this->driver->error;
        }
        elseif ($this->driver->connect_errno) {
            $errNo             = $this->driver->connect_errno;
            $errNote           = $this->driver->connect_error;
            $additionErrorType = 'connect';
        }
        return $errNo ? "[ $additionErrorType error #$errNo '$errNote' ]" : false;
    }

    public function startTransaction()
    {
        if ($this->driver->autocommit(false))
            throw new MqException('autocommit=false was not set', [], $this->getCheckDriverError());
    }

    public function commitTransaction()
    {
        if ($this->driver->commit())
            throw new MqException('commit was not success', [], $this->getCheckDriverError());
        if ($this->driver->autocommit(true))
            throw new MqException('autocommit=true was not set', [], $this->getCheckDriverError());
    }

    public function rollbackTransaction()
    {
        if ($this->driver->rollback())
            throw new MqException('rollback was not success', [], $this->getCheckDriverError());
        if ($this->driver->autocommit(false))
            throw new MqException('rollback: autocommit=false was not set', [], $this->getCheckDriverError());
    }

    /**
     * @param mixed $input
     * @param int   $from
     *
     * @param int   $to
     * @param array $extra
     * @param bool  $isLog
     *
     * @return bool|mysqli_stmt|mysqli_result|array|string|int
     */
    public function performChain($input, $from, $to, $extra, $isLog = false)
    {
        $result = $input;
        for ($i = $from; $i < $to; ++$i) {
            $chainMethodName = $this->chainMethod[$i];
            $result          = $this->$chainMethodName($result, $extra, $isLog);
        }
        return $result;
    }

    /**
     * Request(Req) |--> STMT
     *
     *    **
     *   * *
     *     *
     *     *
     *  *****
     *
     * @param        $req
     * @param array  $extra
     * @param bool   $isLog
     *
     * @throws MqException
     * @throws MqInvalidArgumentException
     * @return bool|mysqli_stmt - Ресурс драйвера, позволяющий повторять приготовленныей запрос с параметрами
     */
    public function fromReqToStmt($req, $extra = [], $isLog = false)
    {
        if (!$req) throw new \MqInvalidArgumentException('Req param not given');
        $req         = $this->getPreprocessedReq($req);
        $this->req   = $req;
        $args        = ['req' => $req];
        $this->stmt  = $stmt = $this->driver->prepare($req);
        $driverError = $this->getCheckDriverError();
        if (!$stmt || $driverError) {
            throw new MqException('STMT generate error', $args, $driverError);
        }
        $this->logDebug(__METHOD__, ['req' => $req, 'stmt' => $stmt], $isLog);
        return $stmt;
    }

    /**
     * @param $req
     *
     * @return mixed
     */
    protected function getPreprocessedReq($req)
    {
        $req = preg_replace_callback('/^([a-z0-9\.\-_]+)\.sql$/i', function ($matches) {
            return file_get_contents(__DIR__ . "/../../../_data/_sql/$matches[1].sql"); // ${ROOT}/_data/_sql -- sql fragments place
        }, $req);
        $req = preg_replace('!\[(?:SCHEME_NAME|TABLE_SCHEMA)\]!', '"' . $this->schemeName . '"', $req); // Название "базы данных" (в терминах mySQL)
        $req = preg_replace('!\[SCHEME_NAME_DEFAULT\]!', '"' . SCHEME_NAME_DEFAULT . '"', $req); // Название "базы данных" (в терминах mySQL)
        return $req;
    }

    /**
     * Record debug log
     *
     * @param        $method
     * @param string $message
     * @param        $isLog
     * @param string $stage
     */
    protected function logDebug($method, $message, $isLog, $stage = '')
    {
        if (!$this->isLog && !$isLog) return;
        if (!is_string($message)) $message = \Invntrm\varDumpRet($message);
        if ($stage) $stage = "$stage:";
        \Invntrm\_d(__CLASS__ . ':' . $method . ':' . $stage . $message, false, 'mq');
    }

    //
    // Other methods
    //

    /**
     * STMT (prepared Query) |--> ITERATIVE (driver iterator)
     *
     *  ****
     *     *
     *  ****
     *  *
     *  ****
     *
     * @param            $stmt mysqli_stmt
     * @param            $extra
     * @param bool       $isLog
     *
     * @throws MqException
     * @throws MqInvalidArgumentException
     * @internal param $sigma
     * @internal param $params
     * @return bool|mysqli_result
     */
    public function fromStmtToIterative($stmt, $extra, $isLog = false)
    {
        //
        // Init
        $sigma  = $extra['sigma'];
        $params = $extra['params'];
        if (!$stmt) throw new \MqInvalidArgumentException('Stmt');
        if (!is_array($params)) $params = [$params];
        $args  = ['req' => $this->req, 'sigma' => $sigma, 'params' => $params];
        $count = [
            'params'       => \Invntrm\true_count($params),
            'sigma'        => strlen($sigma),
            'placeholders' => preg_match_all('!\?!', $this->req)
        ];
        if ($count['sigma'] != $count['params'] || $count['placeholders'] != $count['params'])
            throw new \MqException('Lengths of sigma, params and placeholder are not equal. Counts: '
                . \Invntrm\varDumpRet($count), $args, $this->getCheckDriverError());
        $isArg = !!$count['params'];
        //
        // Bind params
        if ($isArg) {
            array_unshift($params, $sigma); // Расширяем начальным элементом, содержащим сигнатуру
            $tmp = []; // Преобразуем строки в ссылки (требуется функции call_user_func_array)
            foreach ($params as $key => $value) $tmp[$key] = & $params[$key]; // ...
            call_user_func_array([$stmt, 'bind_param'], $tmp); // Запускаем $stmt->bind_param с праметрами из массива
        }
        //
        // Execute and get result
        $isExecuteSuccess = $stmt->execute();
        if (!$isExecuteSuccess)
            throw new MqException('Execute fault', $args, $this->getCheckDriverError());
        $result = $stmt->get_result();
        // fix: result may be not present. it's normal
        // if (!$result) throw new MqException('Get iterative fault', $args, $this->getCheckDriverError());
        $this->logDebug(__METHOD__, ['stmt' => $stmt, 'result' => $result], $isLog);
        if (!$result || !$result->num_rows)
            $this->logDebug(__METHOD__, '[WARN] Result is empty!', $isLog);
        return $result;
    }

    /**
     * ITERATIVE (driver iterator) |--> RAW (raw rows list)
     *
     *   ****
     *      *
     *   ***
     *      *
     *   ****
     *
     * @param       $iterative mysqli_result
     * @param array $extra
     * @param bool  $isLog
     *
     * @throws MqInvalidArgumentException
     * @return bool|mixed
     */
    public function fromIterativeToRaw($iterative, $extra = [], $isLog = false)
    {
        if (preg_match('!^\s*(INSERT|UPDATE)!i', $this->req)) {
            $result = $this->driver->insert_id; // Get affected row id
        }
        else {
            if (!$iterative) throw new \MqInvalidArgumentException('no iterative error');
            $result = $iterative->fetch_all(MYSQLI_ASSOC); // Or get result
        }
        $this->logDebug(__METHOD__, ['iterator' => $iterative, 'result' => $result], $isLog);
        return $result;
    }

    /**
     * RAW (raw rows list) |--> SMART (optimized result)
     *
     *   *  *
     *   *  *
     *   ****
     *      *
     *      *
     *
     * @param       $raw
     *
     * @param array $extra
     * @param bool  $isLog
     *
     * @throws MqInvalidArgumentException
     * @return array|null
     */
    public function fromRawToSmart($raw, $extra = [], $isLog = false)
    {
        $smart = \Invntrm\recursiveDegenerateArrOptimize($raw);
        $this->logDebug(__METHOD__, ['raw' => $raw, 'smart' => $smart], $isLog);
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
    protected $isLog;

    public function __construct($schemeName = '', $isLog = false)
    {
        \Mq::__construct($schemeName, $isLog);
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
     * @example user[name='alx']?pic                    SELECT
     * @example user['alx']?pic                         SELECT (same)
     * @example user[n=1&&L=2]?pic | :.orderCol         SELECT with ORDER BY orderCol DESC
     * @example user[n=1&&L=2]?pic | :.oс,.:oc2         SELECT with ORDER BY orderCol DESC, oc2 ASC
     * @example user[n=1&&L=2]?pic | :.oc       | gc    SELECT with ORDER BY oc DESC GROUP BY gc
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
     * @param bool $isLog
     *
     * @return string
     */
    protected function parse($reqLine, &$sigma, &$params, $isLog = false)
    {
        $reqLine = preg_replace(['/(\W)(order)(\W)/i', '/(\W)(group)(\W)/i'], '$1`$2`$3', $reqLine);
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
            $part2 = preg_replace('/!=\s*null/i',' is not null',$part2);
            $part2 = preg_replace('/=\s*null/i',' is null',$part2);
            return $part2;
        };
        if (!$isLog) $isLog = $this->isLog;
        $limit = $out = $orderOptionStr = '';

        $reqArr = preg_split('!;\s*!', $reqLine); //                                                                Разбивка на отдельные запросы, разделённые символом ';'
        foreach ($reqArr as $req) { // Проход по отдельным alx-запросам
            if (preg_match('!:d$!', $req)) { //                                                                              * Требуется запрос удаления
                preg_match('!^([a-z0-9_]+)\[\s*(.*)\s*\]:d$!i', $req, $part); //                                           Разбить alx-запрос на простые составляющие (для запроса удаления)

                return "DELETE FROM $part[1] WHERE $part[2];";
            }
            if (preg_match('!^[a-z0-9_]+\[.*\]>$!i', $req)) { //                                                                * Требуется запрос вставки
                preg_match('!^([a-z0-9_]+)\[(.*)\]>\s*$!i', $req, $part); //                                           Разбить alx-запрос на простые составляющие (для запроса вставки)
                $table   = $part[1];
                $part[2] = $part2Preprocessor($part[2]);
                return "INSERT INTO $table SET $part[2]";
            }
            preg_match('/^([a-z0-9_]+)\[\s*(.*)\s*\]\?([a-z0-9_,`\.\'=*? >\(\)]+)(?:\|((?:\s*(?:\.:|:\.)\s*[^\|]+?)+))?(?:\|(.*))?\s*$/i', $req, $part); //           Разбить alx-запрос на составляющие
            /*
             * [1]--primary_table [2]--condition [3]--aim [4]-- order_col, order_dir  [5]--group
             */

            $part[2] = $part2Preprocessor(@$part[2]);
            $part[3] = isset($part[3]) ? str_replace('>', ' AS ', $part[3]) : '';
            preg_match_all('!ref:([a-z0-9_]+)!im', $part[2] . ',', $additionRefTables, PREG_PATTERN_ORDER);
            $part[2] = preg_replace('!ref:([a-z0-9_]+)!im', "", $part[2]);
            $part[2] = str_replace('&&', ' AND ', $part[2]);

            $part[1] = $part1 = isset($part[1]) ? $part[1] : '';

            preg_match_all('!([a-z0-9_]+)\.!i', $part[2], $part2ToJoinTables1, PREG_PATTERN_ORDER);
            preg_match_all('!([a-z0-9_]+)\.|count\s*\(([a-z0-9_]+)\)!i', $part[3], $part2ToJoinTables2, PREG_PATTERN_ORDER);

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
                $this->logDebug(__METHOD__, "MultiTablesReq", $isLog, '');
                foreach ($part2ToJoinTables as $table) {
                    if ($part[1] != $table) $part1 .= ' JOIN ' . $table;
                }
                $part1 .= ' ON ';
                $part1_arr          = array();
                $k                  = 0;
                $information_schema = new AlxMq("information_schema");
                $infoStmt           = $information_schema->req("COLUMNS[TABLE_SCHEMA=[SCHEME_NAME_DEFAULT] && TABLE_NAME=*]?COLUMN_NAME", '', false, Mq_Mode::PREPARED_STMT);
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
                //                if (isset($part[5]) && $part[5] != '') $part[6] = $part[1] . '.' . ($part[6] == '' ? 'id' : $part[6]);
            } // if ($part2ToJoinTables_cnt) мультитабличная предобработка

            $part[3] = preg_replace('!(?:^|\W)COUNT([^\(\w]|$)!i', "COUNT($part[1].id)$1", $part[3]); //  Замена COUNT на COUNT(PrimaryTable.id)
            $part[3] = preg_replace('!(?:^|\W)COUNT\s*\(([^\.]*?)\)!i', "COUNT($1.id)", $part[3]); //      Замена COUNT(SomeTable) на COUNT(SomeTable.id)
            if (!isset($part[5])) $part[5] = "";
            if (trim($part[5])) if (preg_match('/\blimit\b/', $part[5])) {
                $limit   = " $part[5] ";
                $part[5] = '';
            }
            else $part[5] = " GROUP BY $part[5] ";
            $part2 = (trim($part[2]) == "" ? "" : " WHERE $part[2]");
            //
            // request
            // SELECT
            if (strpos($part[3], '=') === false) {
                if (preg_match('!(?:^|\s+)id\s*=!', $part[1])) // Необходима одна запись
                    $limit = ' LIMIT 1';
                if (!empty($part[4]) && preg_match('/:/', $part[4])) // Обнаружено правило сортировки
                {
                    $orderOptionStr = 'ORDER BY ';
                    $orderOptionStr .= preg_replace(['/:\.\s*([^\s,]+)/', '/\.:\s*([^\s,]+)/'], ['$1 DESC ', '$1 ASC '], $part[4]);
                }
                $out .= "SELECT $part[3] FROM $part1 $part2 $part[5] $orderOptionStr" . $limit;
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
        $this->logDebug(__METHOD__, ['raw-in' => $reqLine, 'Alx-out' => $out], $isLog, '');

        return $out;
    } // function parseAlxMqSyntax()

    /**
     * Может работать с рядом запросов, разделённых символами !;\s*!
     * См. Mq::parse() for $req syntax
     *
     * @param string       $req
     * @param string       $sigma При выполнении серии запросов нумеруется сквозным образом
     * @param array|bool   $params
     * @param int|\Mq_Mode $mode
     * @param bool         $isLog
     *
     * @return string|array|bool|mysqli_result|mysqli_stmt mysqli_result
     */
    public function req($req, $sigma = '', $params = false, $mode = Mq_Mode::SMART_DATA, $isLog = false)
    {
        //
        // Short form req('class[id=*]?*',$class_id)
        if (is_array($sigma)) {
            $params = $sigma;
            $sigma  = '';
            foreach ($params as $param) {
                $type = gettype($param);
                if (preg_match('!array|object|resource!i', $type)) {
                    throw new MqInvalidArgumentException($param, 'Param not scalar. ' . \Invntrm\varDumpRet([$req, $params]));
                }
                $sigma .= preg_replace(
                    ['!^bool.*$!i', '!^int.*$!i', '!^double$!i', '!^str.*$!i'],
                    ['i', 'i', 'i', 's'],
                    $type
                );
            }
        }
        $req = $this->parse($req, $sigma, $params, $isLog);
        return $this->performChain($req, Mq_Mode::REQUEST, $mode, ['sigma' => $sigma, 'params' => $params], $isLog);
    }
}

class MqException extends Exception
{
    /**
     * @param string $message
     * @param array  $args
     * @param string $driverError
     */
    public function __construct($message, $args, $driverError)
    {
        \Exception::__construct($message
            . " \n\t\t\t Args: " . \Invntrm\varDumpRet($args)
            . " \n\t\t\t DriverError: " . \Invntrm\varDumpRet($driverError)
        );
    }
}

class MqInvalidArgumentException extends InvalidArgumentException
{
    /**
     * @param string|array $params
     * @param string       $message
     */
    public function __construct($params, $message = ' param(s) not given')
    {
        \Exception::__construct(\Invntrm\varDumpRet($params) . $message);
    }
}

// TODO ORM <a>http://ru.wikipedia.org/wiki/ORM</a> Doctrine ORM <a>http://docs.doctrine-project.org/projects/doctrine-orm/en/2.1/tutorials/getting-started-xml-edition.html</a>

