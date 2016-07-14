<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\mongo;

use MongoDB\BSON\Javascript;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\Regex;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Query as MongoQuery;
use think\Exception;
use think\mongo\Connection;
use think\mongo\Query;

class Builder
{
    // connection对象实例
    protected $connection;
    // 查询对象实例
    protected $query;
    // 查询参数
    protected $options = [];
    // 最后插入ID
    protected $insertId = [];
    // 查询表达式
    protected $exp = ['<>' => 'ne', '=' => '=', '>' => 'gt', '>=' => 'gte', '<' => 'lt', '<=' => 'lte', 'in' => 'in', 'not in' => 'nin', 'nin' => 'nin', 'mod' => 'mod', 'exists' => 'exists', 'regex' => 'regex', 'type' => 'type', 'all' => 'all', '> time' => '> time', '< time' => '< time', 'between time' => 'between time', 'not between time' => 'not between time', 'notbetween time' => 'not between time'];

    /**
     * 架构函数
     * @access public
     * @param Connection    $connection 数据库连接对象实例
     * @param Query         $query 数据库查询对象实例
     */
    public function __construct(Connection $connection, Query $query)
    {
        $this->connection = $connection;
        $this->query      = $query;
    }

    /**
     * key分析
     * @access protected
     * @param string $key
     * @return string
     */
    protected function parseKey($key)
    {
        if ('id' == $key && $this->connection->getConfig('pk_convert_id')) {
            $key = '_id';
        }
        return trim($key);
    }

    /**
     * value分析
     * @access protected
     * @param mixed     $value
     * @param string    $field
     * @return string
     */
    protected function parseValue($value, $field = '')
    {
        if ('_id' == $field && !($value instanceof ObjectID)) {
            return new ObjectID($value);
        }
        return $value;
    }

    /**
     * insert数据分析
     * @access protected
     * @param array $data 数据
     * @param array $options 查询参数
     * @return array
     */
    protected function parseData($data, $options)
    {
        if (empty($data)) {
            return [];
        }

        $result = [];
        foreach ($data as $key => $val) {
            $item = $this->parseKey($key);
            if (isset($val[0]) && 'exp' == $val[0]) {
                $result[$item] = $val[1];
            } elseif (is_null($val)) {
                $result[$item] = 'NULL';
            } else {
                $result[$item] = $this->parseValue($val, $key);
            }
        }
        return $result;
    }

    /**
     * Set数据分析
     * @access protected
     * @param array $data 数据
     * @param array $options 查询参数
     * @return array
     */
    protected function parseSet($data, $options)
    {
        if (empty($data)) {
            return [];
        }

        $result = [];
        foreach ($data as $key => $val) {
            $item = $this->parseKey($key);
            if (is_array($val) && isset($val[0]) && in_array($val[0], ['$inc', '$set', '$unset', '$push', '$pushall', '$addtoset', '$pop', '$pull', '$pullall'])) {
                $result[$val[0]][$item] = $this->parseValue($val[1], $key);
            } else {
                $result['$set'][$item] = $this->parseValue($val, $key);
            }
        }
        return $result;
    }

    /**
     * 生成查询过滤条件
     * @access public
     * @param mixed $where
     * @return array
     */
    public function parseWhere($where)
    {
        if (empty($where)) {
            $where = [];
        }

        $filter = [];
        foreach ($where as $logic => $val) {
            foreach ($val as $field => $value) {
                if ($value instanceof \Closure) {
                    // 使用闭包查询
                    $query = new Query($this->connection);
                    call_user_func_array($value, [ & $query]);
                    $filter[$logic][] = $this->parseWhere($query->getOptions('where')[$logic]);
                } else {
                    if (strpos($field, '|')) {
                        // 不同字段使用相同查询条件（OR）
                        $array = explode('|', $field);
                        foreach ($array as $k) {
                            $filter['$or'][] = $this->parseWhereItem($k, $value);
                        }
                    } elseif (strpos($field, '&')) {
                        // 不同字段使用相同查询条件（AND）
                        $array = explode('&', $field);
                        foreach ($array as $k) {
                            $filter['$and'][] = $this->parseWhereItem($k, $value);
                        }
                    } else {
                        // 对字段使用表达式查询
                        $field            = is_string($field) ? $field : '';
                        $filter[$logic][] = $this->parseWhereItem($field, $value);
                    }
                }
            }
        }
        return $filter;
    }

    // where子单元分析
    protected function parseWhereItem($field, $val)
    {
        $key = $field ? $this->parseKey($field) : '';
        // 查询规则和条件
        if (!is_array($val)) {
            $val = ['=', $val];
        }
        list($exp, $value) = $val;

        // 对一个字段使用多个查询条件
        if (is_array($exp)) {
            foreach ($val as $item) {
                $str[] = $this->parseWhereItem($key, $item);
            }
            return $str;
        }

        // 检测操作符
        if (!in_array($exp, $this->exp)) {
            $exp = strtolower($exp);
            if (isset($this->exp[$exp])) {
                $exp = $this->exp[$exp];
            } else {
                throw new Exception('where express error:' . $exp);
            }
        }

        $query = [];
        if ('=' == $exp) {
            // 普通查询
            $query[$key] = $this->parseValue($value, $key);
        } elseif (in_array($exp, ['neq', 'ne', 'gt', 'egt', 'gte', 'lt', 'lte', 'elt', 'mod'])) {
            // 比较运算
            $k           = '$' . $exp;
            $query[$key] = [$k => $this->parseValue($value, $key)];
        } elseif ('all' == $exp) {
            // 满足所有指定条件
            $query[$key] = ['$all', $this->parseValue($value, $key)];
        } elseif ('between' == $exp) {
            // 区间查询
            $value       = is_array($value) ? $value : explode(',', $value);
            $query[$key] = ['$gte' => $value[0], '$lte' => $value[1]];
        } elseif ('not between' == $exp) {
            // 范围查询
            $value       = is_array($value) ? $value : explode(',', $value);
            $query[$key] = ['$lt' => $value[0], '$gt' => $value[1]];
        } elseif ('exists' == $exp) {
            // 字段是否存在
            $query[$key] = ['$exists' => (bool) $value];
        } elseif ('type' == $exp) {
            // 类型查询
            $query[$key] = ['$type' => intval($value)];
        } elseif ('exp' == $exp) {
            // 表达式查询
            $query['$where'] = new Javascript($value);
        } elseif ('like' == $exp) {
            // 模糊查询 采用正则方式
            $query[$key] = new Regex("/" . $value . "/");
        } elseif (in_array($exp, ['nin', 'in'])) {
            // IN 查询
            $value       = is_array($value) ? $value : explode(',', $value);
            $query[$key] = ['$' . $exp, $value];
        } elseif ('regex' == $exp) {
            $query[$key] = new Regex($value);
        } elseif ('< time' == $exp) {
            $query[$key] = ['$lt', $this->parseDateTime($value, $field)];
        } elseif ('> time' == $exp) {
            $query[$key] = ['$gt', $this->parseDateTime($value, $field)];
        } elseif ('between time' == $exp) {
            // 区间查询
            $value       = is_array($value) ? $value : explode(',', $value);
            $query[$key] = ['$gte' => $this->parseDateTime($value[0], $field), '$lte' => $this->parseDateTime($value[1], $field)];
        } elseif ('not between time' == $exp) {
            // 范围查询
            $value       = is_array($value) ? $value : explode(',', $value);
            $query[$key] = ['$lt' => $this->parseDateTime($value[0], $field), '$gt' => $this->parseDateTime($value[1], $field)];
        } else {
            // 普通查询
            $query[$key] = $this->parseValue($value, $key);
        }
        return $query;
    }

    /**
     * 日期时间条件解析
     * @access protected
     * @param string $value
     * @param string $key
     * @return string
     */
    protected function parseDateTime($value, $key)
    {
        // 获取时间字段类型
        $type = $this->query->getTableInfo('', 'type');
        if (isset($type[$key])) {
            $value = strtotime($value) ?: $value;
            if (preg_match('/(datetime|timestamp)/is', $type[$key])) {
                // 日期及时间戳类型
                $value = date('Y-m-d H:i:s', $value);
            } elseif (preg_match('/(date)/is', $type[$key])) {
                // 日期及时间戳类型
                $value = date('Y-m-d', $value);
            }
        }
        return $value;
    }

    /**
     * 获取最后写入的ID 如果是insertAll方法的话 返回所有写入的ID
     * @access public
     * @return mixed
     */
    public function getLastInsID()
    {
        return $this->insertId;
    }

    /**
     * 生成insert BulkWrite对象
     * @access public
     * @param array     $data 数据
     * @param array     $options 表达式
     * @return BulkWrite
     */
    public function insert(array $data, $options = [])
    {
        // 分析并处理数据
        $data = $this->parseData($data, $options);
        $bulk = new BulkWrite;
        if ($insertId = $bulk->insert($data)) {
            $this->insertId = $insertId;
        }
        $this->log('insert', $data, $options);
        return $bulk;
    }

    /**
     * 生成insertall BulkWrite对象
     * @access public
     * @param array     $dataSet 数据集
     * @param array     $options 参数
     * @return BulkWrite
     */
    public function insertAll($dataSet, $options = [])
    {
        $bulk = new BulkWrite;
        foreach ($dataSet as $data) {
            // 分析并处理数据
            $data = $this->parseData($data, $options);
            if ($insertId = $bulk->insert($data)) {
                $this->insertId[] = $insertId;
            }
        }
        $this->log('insert', $dataSet, $options);
        return $bulk;
    }

    /**
     * 生成update BulkWrite对象
     * @access public
     * @param array     $data 数据
     * @param array     $options 参数
     * @return BulkWrite
     */
    public function update($data, $options = [])
    {
        $data  = $this->parseSet($data, $options);
        $where = $this->parseWhere($options['where']);

        if (1 == $options['limit']) {
            $updateOptions = ['multi' => false];
        } else {
            $updateOptions = ['multi' => true];
        }
        $bulk = new BulkWrite;
        $bulk->update($where, $data, $updateOptions);
        $this->log('update', $data, $where);
        return $bulk;
    }

    /**
     * 生成delete BulkWrite对象
     * @access public
     * @param array     $options 参数
     * @return BulkWrite
     */
    public function delete($options)
    {
        $where = $this->parseWhere($options['where']);
        $bulk  = new BulkWrite;
        if (1 == $options['limit']) {
            $deleteOptions = ['limit' => 1];
        } else {
            $deleteOptions = ['limit' => 0];
        }
        $bulk->delete($where, $deleteOptions);
        $this->log('remove', $where, $deleteOptions);
        return $bulk;
    }

    /**
     * 生成Mongo查询对象
     * @access public
     * @param array $options 参数
     * @return MongoQuery
     */
    public function select($options)
    {
        $where = $this->parseWhere($options['where']);
        $query = new MongoQuery($where, $options);
        $this->log('find', $where, $options);
        return $query;
    }

    /**
     * 生成Count命令
     * @access public
     * @param array $options 参数
     * @return Command
     */
    public function count($options)
    {
        $cmd['count'] = $options['table'];
        $cmd['query'] = $this->parseWhere($options['where']);
        foreach (['hint', 'limit', 'maxTimeMS', 'skip'] as $option) {
            if (isset($options[$option])) {
                $cmd[$option] = $options[$option];
            }
        }
        $command = new Command($cmd);
        $this->log('cmd', 'count', $cmd);
        return $command;
    }

    /**
     * 生成distinct命令
     * @access public
     * @param array     $options 参数
     * @param string    $field 字段名
     * @return Command
     */
    public function distinct($options, $field)
    {
        $cmd = [
            'distinct' => $options['table'],
            'key'      => $field,
        ];

        if (!empty($options['where'])) {
            $cmd['query'] = $this->parseWhere($options['where']);
        }

        if (isset($options['maxTimeMS'])) {
            $cmd['maxTimeMS'] = $options['maxTimeMS'];
        }
        $command = new Command($cmd);
        $this->log('cmd', 'distinct', $cmd);
        return $command;
    }

    /**
     * 查询所有的collection
     * @access public
     * @return Command
     */
    public function listcollections()
    {
        $cmd     = ['listCollections' => 1];
        $command = new Command($cmd);
        $this->log('cmd', 'listCollections', $cmd);
        return $command;
    }

    /**
     * 查询数据表的状态信息
     * @access public
     * @return Command
     */
    public function collStats($options)
    {
        $cmd     = ['collStats' => $options['table']];
        $command = new Command($cmd);
        $this->log('cmd', 'collStats', $cmd);
        return $command;
    }

    protected function log($type, $data, $options = [])
    {
        if ($this->connection->getConfig('debug')) {
            $this->connection->log($type, $data, $options);
        }
    }
}
