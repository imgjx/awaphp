<?php
/**
 * 拟真命名器（NameStyle）
 *
 * 早期版本的守卫/诱饵命名带明显指纹（$__awa_p、__junk_xxx、__awa_anti_dbg_xxx、$__KEY、$__CODE），
 * 逆向者一眼即可识别"这是保护壳"并写出批量识别脚本。
 *
 * 本模块从常见业务词汇池中按 (salt, 用途标签) 确定性挑选组合，生成"看起来像业务代码"的名字：
 *   变量名：$cache_handler / $reqState / $userbuffer ...
 *   函数名：validate_handler / getCacheStore / build_query_table ...
 *   标记名：$log_chk_state（强制 snake_case 含下划线）
 *
 * 关键约束：
 *   - markerName() 生成的名字必然含 "_"。base64 字母表不含下划线，因此该串绝不会
 *     出现在任何 base64 密文中，可安全用作"整行自剔除"标记（见 AntiTamper）。
 *   - 同一 salt + 同一标签 => 同一名字，可复现；不同标签互不相同。
 */

declare(strict_types=1);

final class NameStyle
{
    private const HEADS = [
        'data', 'user', 'req', 'resp', 'cache', 'store', 'sess', 'auth', 'token', 'ctx',
        'cfg', 'conf', 'env', 'buf', 'seg', 'meta', 'entry', 'rec', 'item', 'node',
        'conn', 'client', 'server', 'host', 'path', 'file', 'str', 'num', 'arr', 'map',
        'list', 'tree', 'heap', 'queue', 'stack', 'pool', 'tmp', 'cur', 'prev', 'next',
        'last', 'first', 'min', 'max', 'tot', 'cnt', 'sum', 'idx', 'pos', 'off',
        'len', 'size', 'flag', 'stat', 'log', 'msg', 'err', 'out', 'src', 'dst',
        'ref', 'val', 'key', 'id', 'name', 'type', 'kind', 'mode', 'lvl', 'depth',
        'col', 'row', 'cell', 'page', 'view', 'form', 'field', 'input', 'output', 'render',
        'build', 'proc', 'task', 'job', 'work', 'exec', 'run', 'call', 'send', 'recv',
        'load', 'save', 'read', 'write', 'open', 'close', 'init', 'reset', 'clear', 'flush',
        'push', 'pop', 'merge', 'split', 'join', 'walk', 'find', 'match', 'check', 'test',
        'valid', 'parse', 'encode', 'decode', 'hash', 'sign', 'crypt', 'pack', 'unpack', 'comp',
        'b64', 'bin', 'hex', 'sub', 'trim', 'pad', 'norm', 'conv', 'apply', 'handle',
        'dispatch', 'route', 'resolve', 'fetch', 'query', 'guard', 'probe', 'scan', 'trace', 'hook',
        'wrap', 'bind', 'link', 'reg', 'main', 'core', 'shared', 'local', 'common', 'util',
    ];

    private const TAILS = [
        'buffer', 'cache', 'store', 'pool', 'stack', 'queue', 'map', 'list', 'set', 'table',
        'tree', 'graph', 'ctx', 'state', 'machine', 'handler', 'provider', 'resolver', 'builder', 'factory',
        'wrapper', 'adapter', 'proxy', 'stub', 'trace', 'probe', 'marker', 'token', 'seed', 'salt',
        'hash', 'digest', 'cipher', 'stream', 'reader', 'writer', 'cursor', 'gen', 'clock', 'timer',
        'watch', 'scan', 'check', 'guard', 'shield', 'gate', 'lock', 'key', 'vault', 'box',
        'bin', 'trie', 'heap', 'ref', 'ptr', 'val', 'obj', 'item', 'node', 'elem',
        'attr', 'prop', 'meta', 'info', 'data', 'conf', 'env', 'sess', 'conn', 'sock',
        'pipe', 'chan', 'port', 'path', 'file', 'dir', 'url', 'uri', 'html', 'json',
        'xml', 'sql', 'db', 'tbl', 'query', 'filter', 'sort', 'page', 'view', 'form',
        'field', 'param', 'arg', 'opt', 'flag', 'stat', 'log', 'msg', 'err', 'str',
        'num', 'arr', 'idx', 'pos', 'len', 'size', 'cnt', 'sum', 'avg', 'min',
        'max', 'tmp', 'cur', 'prev', 'next', 'last', 'first', 'src', 'dst', 'util',
        'helper', 'core', 'global', 'internal', 'common', 'shared', 'local', 'remote', 'worker', 'agent',
    ];

    private const VERBS = [
        'get', 'set', 'make', 'build', 'do', 'run', 'exec', 'call', 'send', 'load',
        'save', 'read', 'write', 'open', 'close', 'init', 'reset', 'clear', 'flush', 'push',
        'pop', 'merge', 'split', 'join', 'find', 'check', 'valid', 'parse', 'encode', 'decode',
        'hash', 'sign', 'pack', 'norm', 'conv', 'apply', 'handle', 'route', 'resolve', 'fetch',
        'query', 'wrap', 'bind', 'reg', 'scan', 'trace', 'prepare', 'process', 'render', 'filter',
    ];

    public function __construct(private Salt $salt)
    {
        // salt 为空时改用随机 nonce：不同构建产生不同命名，避免"全部产物同构"的指纹；
        // salt 非空时保持确定性 —— 同一 salt 命名完全可复现，整套系统跨文件一致。
        if ($this->salt->isEmpty()) {
            $this->salt = new Salt(bin2hex(random_bytes(8)));
        }
    }

    /** 拟真变量名：$cache_handler / $reqState / $userbuffer */
    public function varName(string $label): string
    {
        $h = $this->pick(self::HEADS, $label . '|h');
        $t = $this->pick(self::TAILS, $label . '|t');
        $style = $this->salt->deriveInt($label . '|st', 0, 2);
        if ($style === 0) {
            return '$' . $h . '_' . $t;
        }
        if ($style === 1) {
            return '$' . $h . ucfirst($t);
        }
        return '$' . $h . $t;
    }

    /**
     * 标记名：强制 snake_case，必然含 "_"。
     * 用途：AntiTamper 的"整行自剔除"标记 —— 含下划线即绝不出现在 base64 密文中。
     */
    public function markerName(string $label): string
    {
        $h = $this->pick(self::HEADS, $label . '|mh');
        $m = $this->pick(['chk', 'ref', 'pos', 'idx', 'ptr', 'off', 'seg', 'pad'], $label . '|mm');
        $t = $this->pick(self::TAILS, $label . '|mt');
        return '$' . $h . '_' . $m . '_' . $t;
    }

    /** 拟真函数名：validate_handler / getCacheStore / build_query_table */
    public function funcName(string $label): string
    {
        $v = $this->pick(self::VERBS, $label . '|fv');
        $n = $this->pick(self::TAILS, $label . '|fn');
        $h = $this->pick(self::HEADS, $label . '|fh');
        $style = $this->salt->deriveInt($label . '|fst', 0, 2);
        if ($style === 0) {
            return $v . '_' . $n;
        }
        if ($style === 1) {
            return $v . ucfirst($n);
        }
        return $h . '_' . $v . '_' . $n;
    }

    /** $GLOBALS 键名：_app_registry 风格（带前导下划线降低与业务键冲突概率） */
    public function globalKey(string $label): string
    {
        $h = $this->pick(self::HEADS, $label . '|gh');
        $t = $this->pick(self::TAILS, $label . '|gt');
        return '_' . $h . '_' . $t;
    }

    /** @param array<int,string> $pool */
    private function pick(array $pool, string $label): string
    {
        $n = count($pool);
        if ($n === 0) {
            return 'item';
        }
        $i = $this->salt->deriveInt($label, 0, $n - 1);
        // 纵深防御：即便派生值越界也强制夹取到合法下标，绝不返回 null。
        $i = max(0, min($n - 1, $i));
        return $pool[$i] ?? 'item';
    }
}
