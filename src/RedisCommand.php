<?php

namespace Pifeifei\PredisCli;

// use http\Client;
use Predis\Client as Predis;
use Predis\Response\ServerException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;


class RedisCommand extends SymfonyCommand
{
    /*
     * Examples of the following constants in the various configurations they can be in
     *
     * releases (phar):
     * const VERSION = '1.8.2';
     * const BRANCH_ALIAS_VERSION = '';
     * const RELEASE_DATE = '2019-01-29 15:00:53';
     * const SOURCE_VERSION = '';
     *
     * snapshot builds (phar):
     * const VERSION = 'd3873a05650e168251067d9648845c220c50e2d7';
     * const BRANCH_ALIAS_VERSION = '1.9-dev';
     * const RELEASE_DATE = '2019-02-20 07:43:56';
     * const SOURCE_VERSION = '';
     *
     * source (git clone):
     * const VERSION = '@package_version@';
     * const BRANCH_ALIAS_VERSION = '@package_branch_alias_version@';
     * const RELEASE_DATE = '@release_date@';
     * const SOURCE_VERSION = '1.8-dev+source';
     */
    const VERSION = '@package_version@';
    const BRANCH_ALIAS_VERSION = '@package_branch_alias_version@';
    const RELEASE_DATE = '@release_date@';
    const SOURCE_VERSION = '1.0.1-dev+source';

    /**
     * @var \Predis\Client
     */
    protected $redis;

    protected $host;
    protected $port;
    protected $password;
    protected $socket;
    protected $db = '-';
    protected $version = '';// 标记下redis-server版本
    protected $pageNumber = 50;//分页显示,每页数量

    /**
     * 命令历史,感觉应该控制下总体数量,暂时1000条,否则把内存撑爆了就太搞笑了..
     *
     * @var array
     */
    protected $history = [
                           'help',
                           'ls',
                           'get',
                           'set',
                           'config',
                           'info',
                           'mv',
                           'rm',
                           'exit',
                           'ttl',
                           'exit',
    ];
    /**
     * @var \Symfony\Component\Console\Input\Input
     */
    protected $input;

    /**
     * @var \Symfony\Component\Console\Output\Output
     */
    protected $output;

    /**
     * 记录下已有key,方便输入
     *
     * @var array
     */
    protected $keys = [];

    /**
     * @var CustomStyle
     */
    protected $io;

    public function configure()
    {
        $this->setName('redis-cli')
            ->addOption('hostname',"H", InputOption::VALUE_OPTIONAL, "Server hostname (default: 127.0.0.1).", '127.0.0.1')
            ->addOption('port',"p", InputOption::VALUE_OPTIONAL, " Server port (default: 6379).", 6379)
            ->addOption('password',"a", InputOption::VALUE_OPTIONAL, "Password to use when connecting to the server.", '')
            ->addOption('socket',"s", InputOption::VALUE_OPTIONAL, "Server socket (overrides hostname and port).", '')
            ->addOption('db',null, InputOption::VALUE_OPTIONAL, "Database number.", 0)
            ->setDescription('<info>PHP 的 Redis 命令行客户端。</info>');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        //        $io = new SymfonyStyle($input, $output);
        $io = new CustomStyle($input, $output);
        $this->io = $io;

        $this->connRedis($input, $output);
        $host = $this->host;
        $port = $this->port;


        do {
            // $command = trim($io->ask("{$host}:{$port}", $this->db));
            $command = trim($this->autoAsk("{$host}:{$port}", $this->db));

            // 每次执行前,查看重连数据库
            try {
                $this->redis->ping();
            } catch (\Exception $exception) {
                $this->redis->connect();
            }

            // 处理命令行逻辑
            switch (true) {
                case stripos($command, 'config') === 0 :
                    // 只弄一个方便阅读配置文件,不打算支持修改
                    $parameter = trim(substr($command, 7));
                    $this->getConfig($parameter);
                    break;
                case stripos($command, 'info') === 0 :
                    // 只弄一个方便阅读配置文件,不打算支持修改
                    $parameter = trim(substr($command, 4));
                    $this->getInfo(empty($parameter) ? '' : $parameter);
                    break;
                case stripos($command, 'select ') === 0 :
                    // 切换数据库
                    $parameter = trim(substr($command, 7));
                    $result = $this->redis->select($parameter);
                    $this->db = $parameter;
                    if ($result->getPayload() === "OK") {
                        $this->db = $parameter;
                        $this->io->success('数据库切换成功!');
                    } else {
                        $this->io->error('数据库切换失败!');
                    }
                    break;
                case stripos($command, 'keys') === 0 :
                    $parameter = trim(substr($command, 4));
                    // 先写这里,回头再抽象
                    $this->listTable($parameter);
                    break;
                case stripos($command, 'ls') === 0 :
                    $parameter = trim(substr($command, 2));
                    // 先写这里,回头再抽象
                    $this->listTable($parameter);
                    break;
                case stripos($command, 'ttl') === 0 :
                    $parameter = trim(substr($command, 3));
                    $parameter = explode(' ', $parameter, 2);
                    if (count($parameter) == 1) {
                        $this->getTtl($parameter);
                    } else {
                        $this->setTtl($parameter);
                    }
                    break;
                case stripos($command, 'persist ') === 0 :
                    $parameter = trim(substr($command, 8));
                    $this->persist($parameter);
                    break;
                case stripos($command, 'mv ') === 0 :
                    $parameter = trim(substr($command, 3));
                    $parameter = explode(' ', $parameter, 2);
                    $this->rename($parameter);
                    break;
                case stripos($command, 'rm ') === 0 :
                    $parameter = trim(substr($command, 3));
                    $parameter = explode(' ', $parameter, 2);
                    $this->rm($parameter);
                    break;
                case stripos($command, 'set ') === 0 :
                    $parameter = trim(substr($command, 4));
                    $parameter = explode(' ', $parameter, 2);
                    $this->set($parameter);
                    break;
                case stripos($command, 'get ') === 0 :
                    $parameter = trim(substr($command, 4));
                    $parameter = explode(' ', $parameter, 2);
                    $this->get($parameter);
                    break;
                case stripos($command, 'exit') === 0 :
                    // 退出
                    $io->success('Bye!');

                    return true;
                    break;
                case stripos($command, 'help') === 0 :
                default:
                    // 帮助列表
                    $io->title('Help List 命令列表');
                    $io->listing([
                            'help : 显示可用命令',
                            'select [0] : 切换数据库,默认0 ',
                            'keys : 列出所有keys',
                            'ls : 列出所有keys',
                            'ls h?llo : 列出匹配keys,?通配1个字符,*通配任意长度字符,[aei]通配选线,特殊符号用\隔开',
                            'ttl key [ttl second] : 获取/设定生存时间,传第二个参数会设置生存时间',
                            'persist key : 移除给定key的生存时间',
                            'mv key new_key : key改名,如果新名字存在则会报错',
                            'rm key : 刪除key,支持通配符匹配',
                            'get key : 获取值',
                            'set key : 设置值',
                            'config  [dir]: 获取配置,可选参数[配置名称(支持通配符)]',
                            'info: 获取当前Redis服务器信息',
                        ]
                    );

                    break;

            }

        } while (strtolower($command) != 'exit');

        $io->success('Bye!');

        return true;
    }

    // 新特性,自动填写答案,上下可以给出自动帮助
    protected function autoAsk($question, $default, $history = [])
    {
        if (empty($history)) {
            $history = $this->history;
            $keys = $this->keys;
            foreach ($keys as $i => $key) {
                $keys[] = 'get ' . $key;
                $keys[] = 'set ' . $key;
            }
            $history = array_merge($history, $keys);
            $history = array_unique($history);
        }
        $questionObj = new Question($question, $default);
        $questionObj->setAutocompleterValues($history);

        $command = $this->io->askQuestion($questionObj);
        if (!in_array($command, $this->history)) {
            $this->history[] = $command;
            // 控制下操作记录仅包含1000条
            if (count($this->history) > 1000) {
                array_shift($this->history);
            }
        }

        return $command;
    }

    protected function connRedis(InputInterface $input, OutputInterface $output)
    {
        do {
            // 输入服务器和port
            $this->host = $host = $input->getOption('hostname'); // $this->autoAsk('Redis服务器host', '127.0.0.1', ['127.0.0.1']);
            $this->port = $port = $input->getOption('port'); // $this->autoAsk('Redis服务器port', '6379', ['6379']);
            $this->password = $password = $input->getOption('password');
            $this->socket = $socket = $input->getOption('socket');
            $this->db = $db = (int) $input->getOption('db');

            if(empty($socket)){
                $config = [
                    'scheme' => 'tcp',
                    'host'   => $host,
                    'port'   => $port,
                    'database' => $db
                ];
            }else{
                $config = ['scheme' => 'unix', 'path' => $socket, 'database' => $db];
            }
            if(!empty($password)){
                $config['password'] = $password;
            }
            try{
                $this->redis = new Predis($config);
                $this->redis->ping();
                $conn = $this->redis->isConnected();
                if(!$conn){
                    dump($conn);
                    sleep(1);
                }
            } catch (ServerException $e) {
                $this->io->error('redis error:'. $e->getMessage());
                exit;
            }
        } while ($conn != true);


        // 连接服务器
        $this->io->success("连接服务器 {$host}:{$port} 成功!");
        // 默认使用 0 号数据库
        $this->db = $db;
        $info = $this->redis->info();
        if (key_exists('redis_version', $info)) {
            $this->version = $info['redis_version'];
        }

        return true;
    }

    protected function persist($key)
    {
        $this->redis->persist($key);
        $this->io->success("{$key} 设置过期时间为永久.");

        return true;
    }

    // 列出配置信息
    protected function getConfig($parameter)
    {
        if (empty($parameter)) {
            $parameter = '*';
        }
        $config = $this->redis->config('GET', $parameter);
        $data = [];
        foreach ($config as $k => $item) {
            $data[] = [
                $k, $item,
            ];
        }
        $this->io->section("CONFIG:");
        $this->io->table(['ITEM', 'VALUE'], $data);
    }

    protected function getInfo($parameter = null)
    {
        if(empty($parameter)){
            $info = $this->redis->info();
        }else{
            $info = $this->redis->info($parameter);
        }

        foreach ($info as $key => $items) {
            $data = [];
            foreach ($items as $k => $v){
                $data[] = [$k, is_string($v) ? $v : http_build_query($v, '', ',')];
            }
            $this->io->section($key." INFO:");
            if(!empty($data)){
                $this->io->table(['ITEM', 'VALUE'], $data);
            }
        }
    }

    // 获取列表和key对应的类型,并返回表格
    protected function listTable($search = '')
    {
        if (empty($search)) {
            $search = '*';
        }
        // 换个方式,大于2.8.0那么使用Scan搜索
        if (version_compare($this->version, '2.8.0')) {
            $iterator = null;
            while ($result = $this->redis->scan($iterator, ['MATCH' => $search, 'COUNT0' => $this->pageNumber])) {
                $data = [];
                foreach ($result[1] as $key) {
//                    dump(get_class($key));
                    $type = $this->redis->type($key);
                    if ($type->getPayload() == "none") {
//                    if ($type == 0) {
                        continue;//不存在那么就直接跳过
                    }
                    // 根据类型显示颜色
                    $type = $this->transType($type->getPayload());
                    $data[$key] = [$type, $key];
                    $this->keys[] = $key;
                }
                $this->io->table(
                    ['TYPE', 'KEY'],
                    $data
                );
                $this->keys = array_unique($this->keys);
                // 最后一页不用了.
                if ($result[0] > 0) {
                    $isBreak = $this->io->confirm('回车继续...');
                    if (!$isBreak) {
                        return true;
                    }
                }else {
                    break;
                }
                $this->keys = array_unique($this->keys);

            }
        } else {
            $keys = $this->redis->keys($search);
            sort($keys);
            $data = [];
            // 加一个分页功能
            foreach ($keys as $row => $key) {
                $type = $this->redis->type($key);
                if ($type == 0) {
                    continue;//不存在那么就直接跳过
                }
                // 根据类型显示颜色
                $type = $this->transType($type);
                $data[$key] = [$type, $key];
                $this->keys[] = $key;
                if ($row != 0 && $row % $this->pageNumber == 0) {
                    $this->io->table(
                        ['TYPE', 'KEY'],
                        $data
                    );
                    $isBreak = $this->io->confirm('回车继续...');
                    if (!$isBreak) {
                        $this->keys = array_unique($this->keys);

                        return true;
                    }
                    $data = [];
                }
            }

            $this->io->table(
                ['TYPE', 'KEY'],
                $data
            );
            $this->keys = array_unique($this->keys);
        }

    }

    // 从 0,1,2..这种类型,转换成显示类型
    protected function transType($type)
    {
        switch ($type) {
            case 1:
            case "string":
                $type = '<fg=black;bg=cyan>STRING</>';
                break;
            case 2:
            case "set":
                $type = '<fg=black;bg=green>SET   </>';
                break;
            case 3:
            case "list":
                $type = '<fg=black;bg=yellow>LIST  </>';
                break;
            case 4:
            case "zset":
                $type = '<fg=black;bg=blue>ZSET  </>';
                break;
            case 5:
            case "hash":
                $type = '<fg=black;bg=magenta>HASH  </>';
                break;
        }

        return $type;
    }

    // 转换为方便输出的ttl格式
    protected function transTtl($ttl)
    {
        switch ($ttl) {
            case -2:
                $ttl = '<fg=black;bg=magenta>KEY不存在</>';
                break;
            case -1:
                $ttl = '<fg=black;bg=cyan>永久</>';
                break;
            default:
        }

        return $ttl;
    }

    // 尝试将string类型数据,转换为数组或反序列化,如果成功那么就返回正常显示
    protected function convertString($string)
    {
        $data = json_decode($string, true);
        if (is_array($data)) {
            return $data;
        }

        $data = @unserialize($string);
        if ($data !== false) {
            return $data;
        }

        return false;
    }

    // 获取并显示数据的ttl生存时间
    protected function getTtl($parameters)
    {
        try {
            $ttl = $this->redis->ttl($parameters[0]);
            // 格式化显示
            $ttl = $this->transTtl($ttl);
            $this->io->table(['KEY', 'TTL (秒s)'], [
                    [$parameters[0], $ttl],
                ]
            );
        } catch (\Exception $e) {
            $this->io->error($e->getMessage());
        }

    }

    // 设置过期时间
    protected function setTtl($parameters)
    {
        try {
            $result = $this->redis->EXPIRE($parameters[0], (integer)$parameters[1]);
            // 格式化显示
            $result = $result == 1 ? ('<info>生存时间设置为: ' . (integer)$parameters[1] . ' (秒)</info>') : '<error>失败</error>';
            $this->io->table(['KEY', 'TTL (秒s)'], [
                    [$parameters[0], $result],
                ]
            );
        } catch (\Exception $e) {
            $this->io->error($e->getMessage());
        }

    }

    // 重命名key
    protected function rename($parameters)
    {
        try {
            if (!key_exists('1', $parameters)) {
                throw new \Exception("缺少第2个参数");
            }
            $result = $this->redis->renameNx($parameters[0], $parameters[1]);
            // 格式化显示
            if ($result == 1) {
                // 成功
                $this->io->success("修改成功");
            } else {
                // 失败
                $this->io->error("修改失败");
            }
        } catch (\Exception $e) {
            $this->io->error($e->getMessage());
        }
    }

    // 删除key
    protected function rm($parameters)
    {
        try {
            // 支持 patten批量删除
            $removeKeys = [];
            while ($keys = $this->redis->scan($iterator, $parameters[0], $this->pageNumber)) {
                $removeKeys = array_merge($removeKeys, $keys);
            }

            if (empty($removeKeys)) {
                throw new \Exception("KEY: {$parameters[0]} 不存在");
            }
            $count = count($removeKeys);
            $confirm = $this->io->confirm("确定要删除 {$parameters[0]} 共{$count}条记录 ?", false);
            if ($confirm) {
                $this->redis->del($removeKeys);
                $this->io->success("删除成功");
            }

        } catch (\Exception $e) {
            $this->io->error($e->getMessage());
        }
    }

    // 设置key
    protected function set($parameters)
    {
        try {
            $key = $parameters[0];
            if ($this->redis->exists($key)) {
                $confirm = $this->io->confirm("KEY: {$key} 已存在,确定覆盖?", true);
                if (!$confirm) {
                    return true;
                }
                // 这里显示下之前旧值,方便修改
                $this->get($parameters);
                $type = $this->redis->type($key);
                // 从整型值,转换为下面兼容的类型.
                switch ($type) {
                    case 1:
                        $type = 'String';
                        break;
                    case 2:
                        $type = 'Set';
                        break;
                    case 3:
                        $type = 'List';
                        break;
                    case 4:
                        $type = 'ZSet';
                        break;
                    case 5:
                        $type = 'Hash';
                        break;
                }
            } else {
                // 优化下这个逻辑,虽然修改已有数据类型是允许的,但是为了方便使用,我这里设置成不能改
                $type = $this->io->choice('请选择数据类型', ['<fg=black;bg=cyan>String</>', '<fg=black;bg=magenta>Hash</>', '<fg=black;bg=yellow>List</>', '<fg=black;bg=green>Set</>', '<fg=black;bg=blue>ZSet</>']);
                $type = strip_tags($type);
            }


            // 处理不同类型数据
            switch ($type) {
                case 'String':
                    $this->setString($key);
                    break;
                case 'Hash':
                    $this->setHash($key);
                    break;
                case 'List':
                    $this->setList($key);
                    break;
                case 'Set':
                    $this->setSet($key);
                    break;
                case 'ZSet':
                    $this->setZSet($key);
                    break;
            }

        } catch (\Exception $e) {
            $this->io->error($e->getMessage());
        }
    }

    protected function setString($key)
    {
        $value = $this->io->ask('请输入值', null, function($value) {
            if (empty($value)) {
                throw new \RuntimeException('不能为空');
            }

            return $value;
        }
        );
        $this->redis->set($key, $value);
        $this->io->success("设置成功!");
        $this->get([$key]);
    }

    protected function setZSet($key)
    {
        do {
            $exit = false;
            $item_key = $this->io->ask("编辑: help查看功能,exit编辑完成退出", "ZSet: " . $key);
            $item_key = trim($item_key);

            switch (true) {
                case stripos($item_key, 'exit') === 0 :
                    $exit = true;
                    $this->get([$key]);
                    break;
                // TODO: rm 和 add 操作似乎不统一? 还没想好用哪种,各有优缺点.
                case stripos($item_key, 'rm ') === 0 :
                    $parameter = trim(substr($item_key, 3));
                    $this->redis->zRem($key, $parameter);
                    $this->io->success("删除成功!");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'add') === 0 :
                    $item_key = $this->io->ask('请输入排序权重值:Score', null, function($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('不能为空');
                        }
                        if (!is_numeric($value)) {
                            throw new \RuntimeException('必须为数字');
                        }

                        return $value;
                    }
                    );
                    $item_value = $this->io->ask('请输入Member值', null, function($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('不能为空');
                        }

                        return $value;
                    }
                    );
                    $this->redis->zAdd($key, (int)$item_key, $item_value);

                    $this->io->success("修改成功!");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'help ') === 0 :
                default:
                    $this->io->title('Hash 命令列表');
                    $this->io->listing([
                            'help : 显示可用命令',
                            'add  : 增加记录',
                            'rm <value> : 移除 value',
                            'exit : 退出编辑',
                        ]
                    );

            }

        } while ($exit != true);

        return true;
    }

    protected function setSet($key)
    {
        do {
            $exit = false;
            $item_key = $this->io->ask("编辑: help查看功能,exit编辑完成退出", "Set: " . $key);
            $item_key = trim($item_key);

            switch (true) {
                case stripos($item_key, 'exit') === 0 :
                    $exit = true;
                    $this->get([$key]);
                    break;
                // TODO: rm 和 add 操作似乎不统一? 还没想好用哪种,各有优缺点.
                case stripos($item_key, 'rm ') === 0 :
                    $parameter = trim(substr($item_key, 3));
                    $this->redis->sRem($key, $parameter);
                    $this->io->success("删除成功!");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'add') === 0 :
                    $item_value = $this->io->ask('请输入value', null, function($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('不能为空');
                        }

                        return $value;
                    }
                    );
                    $this->redis->sAdd($key, $item_value);

                    $this->io->success("修改成功!");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'help ') === 0 :
                default:
                    $this->io->title('Hash 命令列表');
                    $this->io->listing([
                            'help : 显示可用命令',
                            'add  : 增加记录',
                            'rm <value> : 移除 value',
                            'exit : 退出编辑',
                        ]
                    );

            }

        } while ($exit != true);

        return true;
    }

    protected function setList($key)
    {
        do {
            $exit = false;
            $item_key = $this->io->ask("编辑: help查看功能,exit编辑完成退出", "List: " . $key);
            $item_key = trim($item_key);

            switch (true) {
                case stripos($item_key, 'exit') === 0 :
                    $exit = true;
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'lpop') === 0 :
                    $v = $this->redis->lPop($key);
                    $this->io->success("值 [$v] 出队");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'rpop') === 0 :
                    $v = $this->redis->rPop($key);
                    $this->io->success("值 [$v] 出队");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'lpush') === 0 :
                    $item_value = $this->io->ask('请输入value', null, function($value) {
                        if (is_null($value)) {
                            throw new \RuntimeException('不能为空');
                        }

                        return $value;
                    }
                    );
                    $item_value = trim($item_value);
                    $this->redis->lPush($key, $item_value);

                    $this->io->success("值 [$item_value] 左入队");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'rpush') === 0 :
                    $item_value = $this->io->ask('请输入value', null, function($value) {
                        if (is_null($value)) {
                            throw new \RuntimeException('不能为空');
                        }

                        return $value;
                    }
                    );
                    $item_value = trim($item_value);
                    $this->redis->rPush($key, $item_value);

                    $this->io->success("值 [$item_value] 右入队");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'help ') === 0 :
                default:
                    $this->io->title('Hash 命令列表');
                    $this->io->listing([
                            'help  : 显示可用命令',
                            'lpush : 左入队',
                            'rpush : 右入队',
                            'lpop  : 左出队',
                            'rpop  : 右出队',
                            'exit  : 退出编辑',
                        ]
                    );

            }

        } while ($exit != true);

        return true;
    }

    // 设置 hash数据
    protected function setHash($key)
    {
        do {
            $exit = false;
            $item_key = $this->io->ask("编辑: help查看功能,exit编辑完成退出", "Hash: " . $key);
            $item_key = trim($item_key);

            switch (true) {
                case stripos($item_key, 'exit') === 0 :
                    $exit = true;
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'rm ') === 0 :
                    $parameter = trim(substr($item_key, 3));
                    $this->redis->hDel($key, $parameter);
                    $this->io->success("删除成功!");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'mv ') === 0 :
                    $parameter = trim(substr($item_key, 3));
                    $parameter = explode(' ', $parameter, 2);
                    if (count($parameter) != 2) {
                        throw new \RuntimeException('缺少参数');
                    }
                    $v = $this->redis->hGet($key, $parameter[0]);
                    // 改名操作 - 是否用事务?
                    $this->redis->multi();
                    $this->redis->hSet($key, $parameter[1], $v);
                    $this->redis->hDel($key, $parameter[0]);
                    $this->redis->exec();

                    $this->io->success("修改成功!");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'set') === 0 :
                    $item_key = $this->io->ask('请输入key', null, function($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('不能为空');
                        }

                        return $value;
                    }
                    );
                    $item_value = $this->io->ask('请输入value', null, function($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('不能为空');
                        }

                        return $value;
                    }
                    );
                    $this->redis->hSet($key, $item_key, $item_value);

                    $this->io->success("修改成功!");
                    $this->get([$key]);
                    break;
                case stripos($item_key, 'help ') === 0 :
                default:
                    $this->io->title('Hash 命令列表');
                    $this->io->listing([
                            'help : 显示可用命令',
                            'set  : 增加记录',
                            'rm <key> : 移除key',
                            'mv <key> <key_new> : key改名',
                        ]
                    );

            }

        } while ($exit != true);

        return true;
    }

    // 获取key数据详细内容
    protected function get($parameters)
    {
        try {
            if (!$this->redis->exists($parameters[0])) {
                throw new \Exception("KEY: {$parameters[0]} 不存在");
            }
            $key = $parameters[0];
            // 获取类型
            $type = $this->redis->type($key);
            $typeStr = $this->transType($type);
            // 获取ttl
            $ttl = $this->redis->ttl($key);
            $ttlStr = $this->transTtl($ttl);
            // 输出
            $this->io->section('STATUS:');
            $this->io->table(
                ['TYPE', 'KEY', 'TTL'],
                [
                    [$typeStr, $key, $ttlStr],
                ]
            );

            // 根据类型显示值
            // none(key不存在) int(0)
            // string(字符串) int(1)
            // list(列表) int(3)
            // set(集合) int(2)
            // zset(有序集) int(4)
            // hash(哈希表) int(5)
            switch ($type) {
                case 0:
                    throw new \Exception("KEY: {$key} 不存在");
                    break;
                case 1:
                    $this->io->section('VALUE:');
                    $content = (string)$this->redis->get($key);
                    $this->io->text($content);
                    // 尝试转换json或者反序列化,如果成功那么就再显示下.
                    $data = $this->convertString($content);
                    if ($data !== false) {
                        $this->io->section('CONVERSION:');
                        print_r($data);
                    }

                    // 清理下数据
                    unset($content);
                    unset($data);
                    break;
                case 2:
                    // 集合set
                    $this->io->section('VALUE:');
                    $value = $this->redis->sMembers($key);
                    print_r($value);
                    break;
                case 3:
                    // 列表List
                    $this->io->section('VALUE:');
                    $value = $this->redis->lRange($key, 0, -1);
                    print_r($value);
                    break;
                case 4:
                    // 有续集Zset
                    $this->io->section('VALUE:');
                    $value = $this->redis->zRevRange($key, 0, -1);//按照score倒序拍
                    $data = [];
                    foreach ($value as $id => $item) {
                        $data[] = [
                            $id,
                            $this->redis->zScore($key, $item),
                            $item,
                        ];
                    }
                    $this->io->table(
                        ['ID', 'SCORE', 'MEMBER'],
                        $data
                    );
                    break;
                case 5:
                    // 哈希表
                    $this->io->section('VALUE:');
                    $value = (array)$this->redis->hGetAll($key);
                    $data = [];
                    foreach ($value as $key => $item) {
                        $data[] = [
                            $key, $item,
                        ];
                    }
                    $this->io->table(
                        ['KEY', 'MEMBER'],
                        $data
                    );
                    break;

            }

        } catch (\Exception $e) {
            $this->io->error($e->getMessage());
        }
    }

}