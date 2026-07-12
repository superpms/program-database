<?php

namespace pms;

use think\facade\Db;
use think\Model;

/**
 * 基于数据库表的配置注册表。
 *
 * 单表模式：定义与值同表，字段含 parent/name/key/type/value/description。
 * 双表模式：定义表只存 parent/name/key/type/description，值表存 key/value 及隔离字段。
 */
abstract class DbRegistry
{
    protected string $version = "1.0.0";
    protected string $modelClass;
    /**
     * 值表模型类名。为空时走单表模式。
     * @var class-string<Model>|string
     */
    protected string $valueModelClass = '';
    protected string $defaultCreateParentKey = "ROOT";
    protected array $andWhere = [];
    protected array $restoreAttachDatum = [];
    /**
     * 双表模式下创建值记录时附加的字段。
     * @var array
     */
    protected array $valueAttachDatum = [];

    /**
     * 使用 AES-256-CBC 加密字符串
     * @param string $data     要加密的字符串
     * @param string $password 加密密钥
     * @return string 返回 base64 编码的加密结果
     */
    protected function encrypt(string $data, string $password): string
    {
        $iv = openssl_random_pseudo_bytes(16); // 生成随机初始化向量
        $encrypted = openssl_encrypt(
            $data,
            'aes-256-cbc',
            hash('sha256', $password, true), // 使用 SHA-256 哈希密钥
            OPENSSL_RAW_DATA,
            $iv
        );
        return base64_encode($iv . $encrypted);
    }

    /**
     * 解密字符串
     * @param string $data     base64 编码的加密字符串
     * @param string $password 解密密钥
     * @return string|false 返回解密后的原始字符串，失败返回 false
     */
    protected function decrypt(string $data, string $password): bool|string
    {
        $data = base64_decode($data);
        $iv = substr($data, 0, 16); // 提取前16位作为 IV
        $encrypted = substr($data, 16);
        return openssl_decrypt(
            $encrypted,
            'aes-256-cbc',
            hash('sha256', $password, true),
            OPENSSL_RAW_DATA,
            $iv
        );
    }

    /**
     * 是否启用定义表与值表分离模式。
     * @return bool
     */
    protected function isDualMode(): bool
    {
        return $this->valueModelClass !== '';
    }

    /**
     * 解析默认父节点。
     * @param string|null $parentKey 父节点
     * @return string
     */
    protected function getDefaultParentKey(?string $parentKey = null): string
    {
        if ($parentKey === null) {
            $parentKey = $this->defaultCreateParentKey;
        }
        return $parentKey;
    }

    /**
     * 创建定义表模型实例。
     * @return Model
     */
    protected function useModel(): Model
    {
        $name = $this->modelClass;
        return new $name();
    }

    /**
     * 创建值表模型实例。
     * @return Model
     */
    protected function useValueModel(): Model
    {
        $name = $this->valueModelClass;
        return new $name();
    }

    /**
     * 定义表查询条件。双表模式下定义全局共享，不叠加隔离条件。
     * @param array $where 基础条件
     * @return array
     */
    protected function definitionWhere(array $where = []): array
    {
        if ($this->isDualMode()) {
            return $where;
        }
        return [
            ...$where,
            ...$this->andWhere,
        ];
    }

    /**
     * 值表查询条件。单表模式回落到定义表隔离条件。
     * @param array $where 基础条件
     * @return array
     */
    protected function valueWhere(array $where = []): array
    {
        return [
            ...$where,
            ...$this->andWhere,
        ];
    }

    /**
     * 推断写入类型。
     * @param mixed $value 配置值
     * @return string
     */
    protected function convertType(mixed $value): string
    {
        $type = gettype($value);
        if ($type == 'array') {
            return "JSONARRAY";
        }
        return strtoupper($type);
    }

    /**
     * 将配置值转为可落库字符串。
     * @param mixed $value 配置值
     * @return mixed
     */
    protected function setConvertValue(mixed $value)
    {
        $type = gettype($value);
        if ($type == 'array') {
            return json_encode($value);
        } else if ($type == 'object') {
            if (method_exists($value, 'toArray')) {
                return json_encode($value->toArray());
            }
            return json_encode($value);
        } else if ($type == 'boolean') {
            return (int)$value;
        } else {
            return $value;
        }
    }

    /**
     * 按类型还原配置值。
     * @param string $type  类型
     * @param mixed  $value 原始值
     * @return mixed
     */
    protected function getConvertValue(string $type, mixed $value)
    {
        if (empty($type)) {
            return $value;
        }
        switch (strtoupper($type)) {
            case 'BOOL':
            case 'BOOLEAN':
                return (boolean)$value;
            case 'JSONARRAY':
                if (is_string($value)) {
                    $value = json_decode($value, true);
                }
                break;
            case "INTEGER":
                $value = intval($value);
                break;
            case "DOUBLE":
                $value = floatval($value);
                break;
            case "STRING":
                $value = $value . '';
                break;
            case 'NULL':
                $value = null;
                break;
            default:
                break;
        }
        return $value;
    }

    /**
     * 递归查找全部子节点指定字段。
     * @param array  $data               全量节点
     * @param string $key                节点键字段
     * @param string $parentKey          父节点字段
     * @param mixed  $currentParentValue 当前父节点值
     * @param string $resultKey          结果字段
     * @return array
     */
    protected function findAllChildGenealogy(array $data, string $key, string $parentKey, mixed $currentParentValue, string $resultKey): array
    {
        $vals = [];
        foreach ($data as $item) {
            if ($item[$parentKey] === $currentParentValue) {
                $vals = [
                    ...$vals,
                    $item[$resultKey],
                    ...$this->findAllChildGenealogy($data, $key, $parentKey, $item[$key], $resultKey)
                ];
            }
        }
        return $vals;
    }

    /**
     * 读取定义表记录。
     * @param array $where 条件
     * @return array
     */
    protected function findDefinitions(array $where = []): array
    {
        return $this->useModel()::where($this->definitionWhere($where))->select()->toArray();
    }

    /**
     * 读取单条定义。
     * @param string $key 配置键
     * @return array|null
     */
    protected function findDefinition(string $key): ?array
    {
        $config = $this->useModel()::where($this->definitionWhere([
            ['key', '=', $key],
        ]))->find();
        if (empty($config)) {
            return null;
        }
        return $config->toArray();
    }

    /**
     * 读取值表映射。
     * @param array $keys 配置键
     * @return array
     */
    protected function findValueMap(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        if (!$this->isDualMode()) {
            $list = $this->useModel()::where($this->valueWhere([
                ['key', 'in', $keys],
            ]))->select()->toArray();
            $map = [];
            foreach ($list as $item) {
                $map[$item['key']] = $item['value'] ?? null;
            }
            return $map;
        }
        $list = $this->useValueModel()::where($this->valueWhere([
            ['key', 'in', $keys],
        ]))->select()->toArray();
        $map = [];
        foreach ($list as $item) {
            $map[$item['key']] = $item['value'] ?? null;
        }
        return $map;
    }

    /**
     * 保存定义记录。
     * @param array $data 定义数据
     * @return bool
     */
    protected function saveDefinition(array $data): bool
    {
        $key = strtoupper($data['key']);
        $save = [
            'key' => $key,
        ];
        if (array_key_exists('parent', $data)) {
            $save['parent'] = $this->getDefaultParentKey($data['parent']);
        }
        if (array_key_exists('name', $data)) {
            $save['name'] = $data['name'];
        }
        if (array_key_exists('type', $data)) {
            $save['type'] = $data['type'];
        }
        if (array_key_exists('description', $data)) {
            $save['description'] = $data['description'];
        }
        if (!$this->isDualMode() && array_key_exists('value', $data)) {
            $save['value'] = $data['value'];
        }

        $config = $this->useModel()::where($this->definitionWhere([
            ['key', '=', $key],
        ]))->find();
        if (empty($config)) {
            if (!array_key_exists('parent', $save)) {
                $save['parent'] = $this->getDefaultParentKey(null);
            }
            $this->useModel()::create([
                ...$save,
                ...$this->restoreAttachDatum,
            ]);
            return true;
        }
        return $config->save($save) !== false;
    }

    /**
     * 保存值记录。
     * @param string $key   配置键
     * @param mixed  $value 配置值
     * @return bool
     */
    protected function saveValue(string $key, mixed $value): bool
    {
        $key = strtoupper($key);
        $storeValue = $this->setConvertValue($value);
        if (!$this->isDualMode()) {
            $config = $this->useModel()::where($this->valueWhere([
                ['key', '=', $key],
            ]))->find();
            if (empty($config)) {
                return false;
            }
            return $config->save([
                'value' => $storeValue,
                'type' => $this->convertType($value),
            ]) !== false;
        }

        $config = $this->useValueModel()::where($this->valueWhere([
            ['key', '=', $key],
        ]))->find();
        if (empty($config)) {
            $this->useValueModel()::create([
                'key' => $key,
                'value' => $storeValue,
                ...$this->valueAttachDatum,
            ]);
            return true;
        }
        return $config->save([
            'value' => $storeValue,
        ]) !== false;
    }

    /**
     * 获取配置
     * @param string $key 配置键名
     * @return mixed
     */
    public function get(string $key): mixed
    {
        $value = $this->gets([$key]);
        return $value[$key] ?? null;
    }

    /**
     * 获取多个配置
     * @param array $keys 配置项key集合，数组成员支持以下三种格式：
     *                    '配置名称'、
     *                    '配置名称'=>'别名'、
     *                    '配置名称'=>&$configItem
     * @return array
     */
    public function gets(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        $realKeys = [];
        $alias = [];
        $directlyUnder = [];

        foreach ($keys as $index => $value) {
            if (is_string($index)) {
                $realKeys[] = $index;
                if (is_string($value)) {
                    $alias[$index] = $value;
                } else {
                    $directlyUnder[] = $index;
                }
            } else {
                $realKeys[] = $value;
            }
        }

        $configList = $this->findDefinitions([
            ['key', 'in', $realKeys],
        ]);
        $valueMap = $this->findValueMap($realKeys);

        $realConfig = [];
        foreach ($configList as $config) {
            if ($this->isDualMode()) {
                if (!array_key_exists($config['key'], $valueMap)) {
                    $realConfig[$config['key']] = null;
                } else {
                    $realConfig[$config['key']] = $this->getConvertValue($config['type'] ?? 'string', $valueMap[$config['key']]);
                }
            } else {
                $realConfig[$config['key']] = $this->getConvertValue($config['type'] ?? 'string', $config['value'] ?? null);
            }
        }

        foreach ($realKeys as $key) {
            if (!array_key_exists($key, $realConfig)) {
                $realConfig[$key] = null;
            }
        }

        $returnConfig = [];
        foreach ($realConfig as $key => $value) {
            if (in_array($key, $directlyUnder)) {
                $keys[$key] = $value;
            }
            if (isset($alias[$key])) {
                $returnConfig[$alias[$key]] = $value;
            } else {
                $returnConfig[$key] = $value;
            }
        }
        return $returnConfig;
    }

    /**
     * 通过父配置项获取所有子代配置
     * @param string $parent 父配置项key
     * @return array
     */
    public function getP(string $parent): array
    {
        $configList = $this->findDefinitions([
            ['parent', '=', $parent],
        ]);
        $keys = array_column($configList, 'key');
        $valueMap = $this->findValueMap($keys);
        $realConfig = [];
        foreach ($configList as $config) {
            if ($this->isDualMode()) {
                if (!array_key_exists($config['key'], $valueMap)) {
                    $realConfig[$config['key']] = null;
                } else {
                    $realConfig[$config['key']] = $this->getConvertValue($config['type'] ?? 'string', $valueMap[$config['key']]);
                }
            } else {
                $realConfig[$config['key']] = $this->getConvertValue($config['type'] ?? 'string', $config['value'] ?? null);
            }
        }
        return $realConfig;
    }

    /**
     * 设置配置项(存在时返回false)
     * @param string      $key       配置项
     * @param mixed       $value     配置值
     * @param string      $name      配置名称
     * @param string|null $parentKey 父级配置项
     * @return bool
     */
    public function set(string $key, mixed $value, string $name, ?string $parentKey = null): bool
    {
        try {
            $parentKey = $this->getDefaultParentKey($parentKey);
            $create = [
                'parent' => $parentKey,
                'name' => $name,
                'key' => strtoupper($key),
                'type' => $this->convertType($value),
                ...$this->restoreAttachDatum,
            ];
            $this->useModel()->insert($create);
            if ($this->isDualMode()) {
                $this->saveValue($key, $value);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 修改指定key的配置 (不存在则返回false)
     * @param string $key   配置键名
     * @param mixed  $value 配置值
     * @return bool
     */
    public function update(string $key, mixed $value): bool
    {
        if (!$this->has($key)) {
            return false;
        }
        if ($this->isDualMode()) {
            return $this->saveValue($key, $value);
        }

        $saveData = [
            'value' => $this->setConvertValue($value),
            'type' => $this->convertType($value),
        ];
        $status = $this->useModel()::where($this->valueWhere([
            ['key', '=', $key],
        ]))->save($saveData);
        return $status !== false;
    }

    /**
     * 通过父节点修改建指定key 的配置
     * @param string $parentNode 父配置键名
     * @param array  $data       配置数据[key=>value,...]
     * @return bool
     */
    public function updateP(string $parentNode, array $data): bool
    {
        if (!$this->has($parentNode)) {
            return false;
        }
        $nodeList = $this->findDefinitions([
            ['parent', '=', $parentNode],
        ]);
        $value = [];
        foreach ($nodeList as $node) {
            if (isset($data[$node['key']])) {
                $value[$node['key']] = $data[$node['key']];
            }
        }
        if (count($value) === 0) {
            return true;
        }
        Db::startTrans();
        try {
            foreach ($value as $key => $item) {
                $status = $this->update($key, $item);
                if ($status === false) {
                    Db::rollback();
                    return false;
                }
            }
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 保存配置(如果不存在则创建)
     * @param string      $key       配置键名
     * @param mixed       $value     配置值
     * @param string|null $name      配置名称
     * @param string|null $parentKey 父配置键名
     * @return bool
     */
    public function save(string $key, mixed $value, ?string $name = null, ?string $parentKey = null): bool
    {
        if ($this->has($key)) {
            return $this->update($key, $value);
        }
        return $this->set($key, $value, $name ?? $key, $parentKey);
    }

    /**
     * 批量保存(不存在则创建)
     * @param array $array       配置数据[key=>value,...]
     * @param bool  $emptyCreate 配置未定义时是否创建
     * @return bool
     * @throws \Throwable
     */
    public function saveAll(array $array, bool $emptyCreate = true)
    {
        $saveData = [];
        foreach ($array as $key => $value) {
            $saveData[] = [
                'key' => $key,
                'value' => $value,
            ];
        }
        return $this->saveAllRaw($saveData, $emptyCreate);
    }

    /**
     * 批量保存配置 (不存在则创建)
     * @param array $data        配置数据[[key=>xxx,value=>xxx...],...]
     * @param bool  $emptyCreate 配置未定义时是否创建
     * @return bool
     */
    public function saveAllRaw(array $data, bool $emptyCreate = true): bool
    {
        Db::startTrans();
        try {
            $keys = [];
            foreach ($data as $item) {
                if (!is_array($item) || empty($item['key'])) {
                    continue;
                }
                $keys[] = strtoupper($item['key']);
            }
            $nodeList = $keys === [] ? [] : $this->findDefinitions([
                ['key', 'in', $keys],
            ]);
            $dbNodeKeys = array_column($nodeList, 'key');

            foreach ($data as $item) {
                if (!is_array($item) || empty($item['key'])) {
                    continue;
                }
                $itemKey = strtoupper($item['key']);
                $exists = in_array($itemKey, $dbNodeKeys, true);
                if (!$emptyCreate && !$exists) {
                    continue;
                }

                $definition = [
                    'key' => $itemKey,
                ];
                if (array_key_exists('type', $item) && $item['type'] !== null && $item['type'] !== '') {
                    $definition['type'] = $item['type'];
                } else if (!$exists || !$this->isDualMode()) {
                    $definition['type'] = $this->convertType($item['value'] ?? null);
                }
                if (array_key_exists('parent', $item) || !$exists) {
                    $definition['parent'] = $this->getDefaultParentKey($item['parent'] ?? null);
                }
                if (array_key_exists('name', $item)) {
                    $definition['name'] = $item['name'];
                }
                if (array_key_exists('description', $item)) {
                    $definition['description'] = $item['description'];
                }
                if (!$this->isDualMode() && array_key_exists('value', $item)) {
                    $definition['value'] = $this->setConvertValue($item['value']);
                }

                if (!$this->saveDefinition($definition)) {
                    Db::rollback();
                    return false;
                }
                if (!$exists) {
                    $dbNodeKeys[] = $itemKey;
                }

                if ($this->isDualMode() && array_key_exists('value', $item) && $item['value'] !== null) {
                    if (!$this->saveValue($itemKey, $item['value'])) {
                        Db::rollback();
                        return false;
                    }
                }
            }
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 通过父节点批量保存配置(不存在则创建)
     * @param string $parentKey 父配置键
     * @param array  $data      配置数据[ [key=>value,name=>''],...]
     * @return bool
     */
    public function saveP(string $parentKey, array $data): bool
    {
        if ($this->has($parentKey)) {
            return false;
        }
        if (empty($data)) {
            return true;
        }
        $value = [];
        foreach ($data as $item) {
            if (!is_array($item) || empty($item['key'])) {
                continue;
            }
            $val = [
                'key' => strtoupper($item['key']),
                'value' => $item['value'] ?? null,
                'type' => $this->convertType($item['value'] ?? null),
                'parent' => $parentKey,
            ];
            if (array_key_exists('name', $item)) {
                $val['name'] = $item['name'];
            }
            if (array_key_exists('description', $item)) {
                $val['description'] = $item['description'];
            }
            $value[] = $val;
        }
        return $this->saveAllRaw($value, true);
    }

    /**
     * 配置是否存在
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->have([$key]);
    }

    /**
     * 多个配置是否存在
     * @param string[] $keys 配置项
     * @return bool
     */
    public function have(array $keys): bool
    {
        return $this->haveCount($keys) === count($keys);
    }

    /**
     * 获取存在配置项数量
     * @param string[] $keys
     * @return int
     */
    public function haveCount(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }
        return $this->useModel()::where($this->definitionWhere([
            ['key', 'in', $keys],
        ]))->count();
    }

    /**
     * 删除配置项
     * @param string|array $key
     * @return bool
     */
    public function delete(string|array $key): bool
    {
        $keys = is_array($key) ? $key : [$key];
        if ($keys === []) {
            return true;
        }
        if ($this->isDualMode()) {
            return $this->useValueModel()->where($this->valueWhere([
                ['key', 'in', $keys],
            ]))->delete() !== false;
        }
        $symbol = is_array($key) ? 'in' : '=';
        return $this->useModel()->where($this->valueWhere([
            ['key', $symbol, $key],
        ]))->delete() !== false;
    }

    /**
     * 删除配置定义。
     * @param string|array $key 配置键
     * @return bool
     */
    public function deleteDefinition(string|array $key): bool
    {
        $symbol = is_array($key) ? 'in' : '=';
        return $this->useModel()->where($this->definitionWhere([
            ['key', $symbol, $key],
        ]))->delete() !== false;
    }

    /**
     * 删除配置项(包含所有子集配置)
     * @param string $key 起始配置项
     * @return bool
     */
    public function deleteP(string $key): bool
    {
        $data = $this->findDefinitions();
        $keys = $this->findAllChildGenealogy($data, 'key', 'parent', $key, 'key');
        $keys[] = $key;
        if ($this->isDualMode()) {
            return $this->delete($keys);
        }
        return $this->useModel()->where($this->valueWhere([
            ['key', 'in', $keys],
        ]))->delete() !== false;
    }

    /**
     * 备份注册表到文件
     * @param string      $backPath 文件路径
     * @param string|null $password 密码
     * @return bool
     */
    public function backup(string $backPath, ?string $password = null): bool
    {
        $path = pathinfo($backPath)['dirname'];
        if (!is_dir($path) && !file_exists($path)) {
            @mkdir($path, 0777, true);
        }
        $file = fopen($backPath, 'w');
        if (!$file) {
            return false;
        }
        $data = $this->generateBackupStr($password);
        fwrite($file, $data);
        fclose($file);
        return true;
    }

    /**
     * 生成注册表备份数据
     * @param string|null $password 密码
     * @return string
     */
    public function generateBackupStr(?string $password = null): string
    {
        $definitions = $this->findDefinitions();
        if ($this->isDualMode()) {
            $keys = array_column($definitions, 'key');
            $valueMap = $this->findValueMap($keys);
            $data = [];
            foreach ($definitions as $item) {
                $data[] = [
                    'parent' => $item['parent'] ?? null,
                    'name' => $item['name'] ?? null,
                    'key' => $item['key'],
                    'type' => $item['type'] ?? null,
                    'value' => $valueMap[$item['key']] ?? null,
                    'description' => $item['description'] ?? null,
                ];
            }
        } else {
            $data = $definitions;
        }
        $payload = [
            'registry' => $this->version,
            'data' => $data
        ];
        $payload = json_encode($payload, 320);
        if ($password === null) {
            return $payload;
        }
        return $this->encrypt($payload, $password);
    }

    /**
     * 格式化注册表
     * @return bool
     */
    public function format(): bool
    {
        if ($this->isDualMode()) {
            $this->useValueModel()->where($this->andWhere)->delete();
            return true;
        }
        $model = $this->useModel();
        $table = $model->getTable();
        $model->getConnection()->execute("truncate $table");
        return true;
    }

    /**
     * 使用文件还原注册表
     * @param string      $filePath 文件地址
     * @param string|null $password 密码
     * @return bool
     */
    public function restore(string $filePath, ?string $password = null): bool
    {
        $data = file_get_contents($filePath);
        if (!$data) {
            return false;
        }
        $data = $this->unpackBackupStr($data, $password);
        if ($data === false) {
            return false;
        }
        $data = json_decode($data, true);
        if ($data === null) {
            return false;
        }
        $data = $data['data'] ?? [];
        $saveData = [];
        foreach ($data as $v) {
            if (isset($v['key']) && isset($v['parent'])) {
                $item = [
                    ...$this->restoreAttachDatum,
                    'parent' => $v['parent'],
                    'name' => $v['name'] ?? null,
                    'key' => strtoupper($v['key']),
                    'type' => $v['type'] ?? null,
                    'description' => $v['description'] ?? null,
                ];
                if (!$this->isDualMode()) {
                    $item['value'] = $v['value'] ?? null;
                } else if (array_key_exists('value', $v)) {
                    $item['value'] = $v['value'];
                }
                $saveData[] = $item;
            }
        }

        Db::startTrans();
        try {
            $keys = array_column($saveData, 'key');
            if (!$this->isDualMode()) {
                if ($keys !== []) {
                    $this->useModel()::where($this->valueWhere([
                        ['key', 'in', $keys],
                    ]))->delete();
                }
                foreach ($saveData as $save) {
                    $this->useModel()::create($save);
                }
            } else {
                foreach ($saveData as $save) {
                    $definition = [
                        'key' => $save['key'],
                        'parent' => $save['parent'],
                        'name' => $save['name'],
                        'type' => $save['type'],
                        'description' => $save['description'],
                    ];
                    if (!$this->saveDefinition($definition)) {
                        Db::rollback();
                        return false;
                    }
                    if (array_key_exists('value', $save) && $save['value'] !== null && $save['value'] !== '') {
                        if (!$this->saveValue($save['key'], $save['value'])) {
                            Db::rollback();
                            return false;
                        }
                    }
                }
            }
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 解压注册表备份文件的内容(密码错误或还原失败时返回false)
     * @param string      $data     文件内容字符串
     * @param string|null $password 密码
     * @return string|false
     */
    public function unpackBackupStr(string $data, ?string $password = null): string|false
    {
        if ($password === null) {
            return $data;
        }
        return $this->decrypt($data, $password);
    }

    /**
     * 创建注册表实例。
     * @param mixed ...$args 构造参数
     * @return static
     */
    public static function inst(...$args): static
    {
        return new static(...$args);
    }

}
