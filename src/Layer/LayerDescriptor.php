<?php
/**
 * 分层描述系统（LayerDescriptor）
 *
 * 每一层壳的"权威 schema"：类型、中文标签、说明、可自定义参数表。
 * 三类消费者共用同一份定义：
 *   - GUI：据此渲染"层列表 + 参数编辑器"（每层独立可定制）；
 *   - CLI / preset / 分层 JSON：据此校验与补全参数（未知参数告警、缺参数取默认）；
 *   - Obfuscator::layerConfig()：据此把层参数映射为内部配置键。
 *
 * 参数类型：
 *   bool   复选框
 *   int    整数（min/max 夹取）
 *   select 单选（options 为 [值 => 显示名]；值 '' 表示关闭）
 *   text   单行文本
 *   code   多行代码（注入层专用）
 *   list   逗号分隔列表（如 probes 多选）
 *
 * 新增层参数的标准流程：改这里 → 在 Obfuscator::layerConfig() 加映射 →
 * 在对应壳实现里读取。三处不改齐时 validate() 会告警，不会静默失效。
 */

declare(strict_types=1);

final class LayerDescriptor
{
    public const T_BOOL   = 'bool';
    public const T_INT    = 'int';
    public const T_SELECT = 'select';
    public const T_TEXT   = 'text';
    public const T_CODE   = 'code';
    public const T_LIST   = 'list';

    /**
     * 全部层定义（有序：token 级 → shell → 特殊）。
     * @return array<string,array{
     *   kind:string, label:string, desc:string,
     *   params:array<string,array{label:string,type:string,default:mixed,min?:int,max?:int,options?:array<string,string>,hint?:string}>,
     *   tags:array<int,string>
     * }>
     */
    public static function all(): array
    {
        return [
            // ===================== token 级层（作用于同一 token 流，按序累积）=====================
            'var' => [
                'kind'  => 'token',
                'label' => '变量名混淆',
                'desc'  => '把全部局部/全局变量重命名为拟真随机名（无 _awa 指纹）',
                'params' => [
                    'length' => ['label' => '名称长度', 'type' => self::T_INT, 'default' => 8, 'min' => 4, 'max' => 32, 'hint' => '4-32'],
                ],
                'tags' => ['基础'],
            ],
            'func' => [
                'kind'  => 'token',
                'label' => '函数名混淆',
                'desc'  => '重命名自定义函数；可选调用点改写与编码',
                'params' => [
                    'length' => ['label' => '名称长度', 'type' => self::T_INT, 'default' => 10, 'min' => 4, 'max' => 32, 'hint' => '4-32'],
                    'call'   => ['label' => '调用点改写', 'type' => self::T_BOOL, 'default' => true, 'hint' => '调用处同步改为新名'],
                    'enc'    => ['label' => '调用点编码', 'type' => self::T_BOOL, 'default' => false, 'hint' => '调用名再做一层编码还原'],
                ],
                'tags' => ['基础'],
            ],
            'class' => [
                'kind'  => 'token',
                'label' => '类/成员混淆',
                'desc'  => '重命名类名、方法名与属性名',
                'params' => [
                    'on' => ['label' => '启用', 'type' => self::T_BOOL, 'default' => true],
                ],
                'tags' => ['基础'],
            ],
            'controlflow' => [
                'kind'  => 'token',
                'label' => '控制流混淆',
                'desc'  => '分支扁平化 + GOTO 迷宫，静态分析失效',
                'params' => [
                    'cfg'      => ['label' => '平坦化强度', 'type' => self::T_SELECT, 'default' => 'medium',
                                   'options' => ['' => '关闭', 'low' => 'low', 'medium' => 'medium', 'high' => 'high']],
                    'goto'     => ['label' => 'GOTO 层数', 'type' => self::T_INT, 'default' => 1, 'min' => 0, 'max' => 5, 'hint' => '0=关闭'],
                    'goto_cpx' => ['label' => 'GOTO 复杂度', 'type' => self::T_INT, 'default' => 4, 'min' => 0, 'max' => 16, 'hint' => '每个函数的迷宫跳转密度'],
                    'trap'     => ['label' => '诱饵死状态', 'type' => self::T_INT, 'default' => 2, 'min' => 0, 'max' => 4, 'hint' => '每个分派器注入的不可达分支数（0=关闭），强行跳入即自锁'],
                ],
                'tags' => ['控制流'],
            ],
            'number' => [
                'kind'  => 'token',
                'label' => '数字编码',
                'desc'  => '数字常量改写为等价的十六进制/运算表达式',
                'params' => [
                    'on' => ['label' => '启用', 'type' => self::T_BOOL, 'default' => true],
                ],
                'tags' => ['编码'],
            ],
            'string' => [
                'kind'  => 'token',
                'label' => '字符串编码',
                'desc'  => '字符串字面量编码后运行时还原，明文不可见',
                'params' => [
                    'xor'    => ['label' => 'XOR 密钥装配', 'type' => self::T_BOOL, 'default' => true, 'hint' => '密钥运行时装配，不明文落地'],
                    'length' => ['label' => '按长度分段', 'type' => self::T_INT, 'default' => 8, 'min' => 0, 'max' => 64, 'hint' => '每 N 字符一段（0=不按长度）'],
                    'split'  => ['label' => '拆分份数', 'type' => self::T_INT, 'default' => 1, 'min' => 1, 'max' => 8, 'hint' => '整串拆成 N 段（1=不拆）'],
                ],
                'tags' => ['编码'],
            ],
            'comment' => [
                'kind'  => 'token',
                'label' => '注释清除',
                'desc'  => '移除全部注释，消除线索',
                'params' => [],
                'tags' => ['清理'],
            ],
            'token' => [
                'kind'  => 'token',
                'label' => 'Token 形态混淆',
                'desc'  => '关键字大小写随机化 / 字面量等价形态 / 引号翻转 / heredoc 化，杀死 grep 特征',
                'params' => [
                    'case'    => ['label' => '关键字大小写', 'type' => self::T_BOOL, 'default' => true, 'hint' => 'ecHo/WhIlE/fUnCtIoN，反关键字 grep'],
                    'literal' => ['label' => '字面量形态', 'type' => self::T_BOOL, 'default' => true, 'hint' => 'true→!0、(int)↔(integer)、array()→[]'],
                    'quote'   => ['label' => '引号翻转', 'type' => self::T_BOOL, 'default' => true, 'hint' => "'abc'↔\"abc\"（安全时）"],
                    'heredoc' => ['label' => 'heredoc 化', 'type' => self::T_BOOL, 'default' => false, 'hint' => '纯字面量串改写为 nowdoc 形态'],
                    'decoy'   => ['label' => '诱饵注释数', 'type' => self::T_INT, 'default' => 3, 'min' => 0, 'max' => 50, 'hint' => '顶层误导性注释（0=关闭）'],
                ],
                'tags' => ['形态'],
            ],
            'stat' => [
                'kind'  => 'token',
                'label' => '语句拆分',
                'desc'  => '复合语句拆为多条单语句，打乱结构',
                'params' => [],
                'tags' => ['结构'],
            ],
            'ws' => [
                'kind'  => 'token',
                'label' => '空白压缩',
                'desc'  => '去换行 + 压缩空白，产物单行化',
                'params' => [],
                'tags' => ['清理'],
            ],
            'dead' => [
                'kind'  => 'token',
                'label' => '死代码注入',
                'desc'  => '插入永不执行的诱饵分支，膨胀分析面',
                'params' => [
                    'density' => ['label' => '密度', 'type' => self::T_INT, 'default' => 3, 'min' => 1, 'max' => 10, 'hint' => '1-10'],
                ],
                'tags' => ['反分析'],
            ],
            'junk_runtime' => [
                'kind'  => 'token',
                'label' => '动态垃圾指令',
                'desc'  => '真实执行的垃圾运算流（无输出无副作用），干扰动态分析',
                'params' => [
                    'intensity' => ['label' => '强度', 'type' => self::T_INT, 'default' => 3, 'min' => 1, 'max' => 10, 'hint' => '插入密度'],
                    'fake_b64'  => ['label' => '诱饵 base64', 'type' => self::T_BOOL, 'default' => true, 'hint' => '插入永不解码的诱饵串，毒化批量解码工具'],
                    'fake_echo' => ['label' => '诱饵输出', 'type' => self::T_BOOL, 'default' => true, 'hint' => '经输出缓冲执行后立即清除，不留痕迹'],
                    'tangle'    => ['label' => '执行缠结', 'type' => self::T_BOOL, 'default' => false, 'hint' => '垃圾变量参与真实整数赋值并恒等式抵消，删垃圾即出错'],
                ],
                'tags' => ['反分析'],
            ],
            'semantic' => [
                'kind'  => 'token',
                'label' => '语义等价变换',
                'desc'  => '表达式/布尔/控制流的恒等改写（x*2→x+x、De Morgan、for→while 等），破坏静态模式匹配',
                'params' => [
                    'strength' => ['label' => '变换强度', 'type' => self::T_INT, 'default' => 2, 'min' => 1, 'max' => 3,
                                   'hint' => '1=算术恒等 2=+布尔/分支 3=+控制流（for→while）'],
                ],
                'tags' => ['反分析'],
            ],

            // ===================== shell 层（按序包裹源码字符串）=====================
            'shell:vm' => [
                'kind'  => 'shell',
                'label' => 'VM 虚拟机壳',
                'desc'  => '源码编译为自定义 ISA 字节码，解释器逐条分发执行',
                'params' => [
                    'flatten'     => ['label' => '控制流平坦化', 'type' => self::T_BOOL, 'default' => false, 'hint' => '编译期把函数体改为状态机分发'],
                    'stateMachine' => ['label' => '状态机模式', 'type' => self::T_BOOL, 'default' => false, 'hint' => '最高强度：跳转表 + 指令重排（flatten 的超集）'],
                    'chunks'      => ['label' => '字节码分片', 'type' => self::T_INT, 'default' => 3, 'min' => 1, 'max' => 16, 'hint' => '分片数越多越难拼还原'],
                    'layers'      => ['label' => 'VM 嵌套层数', 'type' => self::T_INT, 'default' => 1, 'min' => 1, 'max' => 3, 'hint' => '真·嵌套 VM：>=2 时把上一层 VM 产物再 VM 化，复杂度指数级上升'],
                    'modules'     => ['label' => '模块目录', 'type' => self::T_TEXT, 'default' => '', 'hint' => 'ModuleLinker JSON 清单目录：命中清单的函数调用内联进同一 Program，产物无路径痕迹'],
                    'junk'        => ['label' => '字节码垃圾指令', 'type' => self::T_INT, 'default' => 0, 'min' => 0, 'max' => 200, 'hint' => 'VM 字节码级真实垃圾指令段数（每段多字节码），栈平衡无副作用，抗静态分析'],
                    'keychain'       => ['label' => '密钥链分片加密', 'type' => self::T_BOOL, 'default' => false, 'hint' => '分片密钥哈希链派生（ki=sha256(k[i-1]‖i)），运行期顺序推导，单点密钥无法还原其它分片'],
                    'builtinReplace' => ['label' => '内置函数名编码', 'type' => self::T_BOOL, 'default' => false, 'hint' => 'natTbl 内置函数名盐化编码进二进制包、运行期解码，包内无 strlen/array_map 等明文'],
                    'morph'          => ['label' => '解释器运行时变形', 'type' => self::T_BOOL, 'default' => false, 'hint' => '派发叠加独立第二层 scramble，每构建 dispatch 数学不同，消除固定指纹'],
                    'fakeFog'        => ['label' => '诱饵函数', 'type' => self::T_INT, 'default' => 0, 'min' => 0, 'max' => 200, 'hint' => '注入 N 个永不调用的栈平衡假函数，淹没真实 opcode 集合、膨胀函数表'],
                    'probe'          => ['label' => '反分析探针', 'type' => self::T_INT, 'default' => 0, 'min' => 0, 'max' => 50, 'hint' => '反调试/反篡改探针矩阵（xdebug/phpdbg/uopz 检测 + 字节码 crc 自校验），命中即静默停止'],
                    'cpool'          => ['label' => '常量池加密', 'type' => self::T_BOOL, 'default' => true, 'hint' => 'PUSH_C 字面量抽入独立常量池（索引引用），池以独立密钥加密，静态分析看不到明文常量'],
                ],
                'tags' => ['VM'],
            ],
            'shell:eval' => [
                'kind'  => 'shell',
                'label' => 'EVAL 多层加壳',
                'desc'  => '多层 eval 壳：每层随机载荷方案 + 分片乱序 + 诱饵变量',
                'params' => [
                    'layers' => ['label' => '层数', 'type' => self::T_INT, 'default' => 3, 'min' => 1, 'max' => 10, 'hint' => '嵌套 eval 层数'],
                ],
                'tags' => ['壳'],
            ],
            'shell:gz' => [
                'kind'  => 'shell',
                'label' => 'GZ 压缩壳',
                'desc'  => 'gzdeflate + base64 单行压缩壳',
                'params' => [
                    'decoy'  => ['label' => '诱饵载荷', 'type' => self::T_BOOL, 'default' => true, 'hint' => '追加永不使用的诱饵压缩串'],
                    'chunks' => ['label' => '载荷分片', 'type' => self::T_INT, 'default' => 3, 'min' => 1, 'max' => 6, 'hint' => 'base64 串切片乱序存入变量'],
                ],
                'tags' => ['壳'],
            ],
            'shell:anti_debug' => [
                'kind'  => 'shell',
                'label' => '反调试探针',
                'desc'  => '检测 Xdebug/phpdbg/uopz 等调试分析环境，命中即终止',
                'params' => [
                    'probes'    => ['label' => '探针组', 'type' => self::T_LIST, 'default' => 'ext,func,ini,env,sapi,trace',
                                    'options' => ['ext' => '扩展加载', 'func' => '函数存在', 'ini' => 'ini 项', 'env' => '环境变量', 'sapi' => 'phpdbg', 'trace' => '调用栈'],
                                    'hint' => '逗号分隔，可多选'],
                    'intensity' => ['label' => '强度', 'type' => self::T_INT, 'default' => 2, 'min' => 1, 'max' => 4,
                                    'hint' => '1=明文内联 2=gz+base64 3=拟真命名 4=叠加 XOR'],
                ],
                'tags' => ['反调试'],
            ],
            'shell:anti_hook' => [
                'kind'  => 'shell',
                'label' => '防 hook eval',
                'desc'  => 'eval 完整性探针：eval 被换成 echo/print 立即自杀',
                'params' => [
                    'strict' => ['label' => '严格模式', 'type' => self::T_BOOL, 'default' => true, 'hint' => '同时检测可劫持函数的扩展'],
                ],
                'tags' => ['反调试'],
            ],
            'shell:anti_tamper' => [
                'kind'  => 'shell',
                'label' => '自校验防篡改',
                'desc'  => '分段 sha1 + 掩码自校验 + shutdown 二次校验',
                'params' => [
                    'segments'  => ['label' => '分段数', 'type' => self::T_INT, 'default' => 4, 'min' => 2, 'max' => 8, 'hint' => '受护体切分校验段数'],
                    'shutdown'  => ['label' => '关机二次校验', 'type' => self::T_BOOL, 'default' => true, 'hint' => 'register_shutdown_function 收尾复检'],
                ],
                'tags' => ['反调试'],
            ],
            'shell:license' => [
                'kind'  => 'shell',
                'label' => '授权绑定',
                'desc'  => '按域名 / 有效期授权，越权即终止',
                'params' => [
                    'domain' => ['label' => '授权域名', 'type' => self::T_TEXT, 'default' => '', 'hint' => 'a.com,b.com（留空不校验域名）'],
                    'expire' => ['label' => '到期日期', 'type' => self::T_TEXT, 'default' => '', 'hint' => 'YYYY-MM-DD（留空永不过期）'],
                ],
                'tags' => ['授权'],
            ],
            'shell:structpoison' => [
                'kind'  => 'shell',
                'label' => '结构指纹毒化壳',
                'desc'  => '文件结构指纹参与解密密钥派生：美化/篡改产物 → 密钥错位解出乱码，无可绕过校验分支（毒化而非退出）',
                'params' => [
                    'rounds' => ['label' => 'KDF 轮数', 'type' => self::T_INT, 'default' => 3, 'min' => 1, 'max' => 8, 'hint' => '指纹密钥派生迭代轮数'],
                ],
                'tags' => ['反分析'],
            ],
            'shell:honeypot' => [
                'kind'  => 'shell',
                'label' => '蜜罐函数（钓鱼陷阱）',
                'desc'  => '拟真高价值命名假函数（deriveMasterKey 类），正常永不调用；破解者调用/插桩即触发拖慢 + 返回钓鱼假密钥',
                'params' => [
                    'count' => ['label' => '蜜罐数量', 'type' => self::T_INT, 'default' => 4, 'min' => 0, 'max' => 16, 'hint' => '注入的钓鱼函数个数（0=关闭）'],
                ],
                'tags' => ['反分析'],
            ],

            // ===================== 特殊层 =====================
            'inject' => [
                'kind'  => 'inject',
                'label' => '代码注入',
                'desc'  => '向当前层产物注入自定义 PHP 代码（顶部或尾部注入区）',
                'params' => [
                    'code'       => ['label' => '注入代码', 'type' => self::T_CODE, 'default' => '', 'hint' => '任意 PHP 语句，自动 php -l 校验'],
                    'code_at'    => ['label' => '注入位置', 'type' => self::T_SELECT, 'default' => 'after_shell',
                                     'options' => ['after_shell' => '尾部注入区', 'top' => '文件顶部']],
                    'anti_debug' => ['label' => '附带反调试', 'type' => self::T_BOOL, 'default' => false, 'hint' => '注入前先套一层反调试探针'],
                ],
                'tags' => ['自定义'],
            ],
        ];
    }

    public static function get(string $name): ?array
    {
        $all = self::all();
        return $all[$name] ?? null;
    }

    public static function label(string $name): string
    {
        $d = self::get($name);
        return $d === null ? $name : $d['label'];
    }

    /** @return array<string,array{label:string,type:string,default:mixed,min?:int,max?:int,options?:array<string,string>,hint?:string}> */
    public static function params(string $name): array
    {
        $d = self::get($name);
        return $d === null ? [] : $d['params'];
    }

    /**
     * 该层参数的默认值（用于 preset 补全 / GUI 初始渲染）。
     * @return array<string,mixed>
     */
    public static function defaults(string $name): array
    {
        $out = [];
        foreach (self::params($name) as $k => $p) {
            $out[$k] = $p['default'];
        }
        return $out;
    }

    /**
     * 归一化一层参数：填默认值 + 按类型夹取/清洗。
     * @return array<string,mixed>
     */
    public static function normalize(string $name, array $params): array
    {
        $out = [];
        foreach (self::params($name) as $k => $p) {
            $v = $params[$k] ?? $p['default'];
            switch ($p['type']) {
                case self::T_BOOL:
                    $out[$k] = (bool) $v;
                    break;
                case self::T_INT:
                    $i = (int) $v;
                    if (isset($p['min'])) { $i = max($p['min'], $i); }
                    if (isset($p['max'])) { $i = min($p['max'], $i); }
                    $out[$k] = $i;
                    break;
                case self::T_SELECT:
                    $s = (string) $v;
                    $out[$k] = array_key_exists($s, $p['options'] ?? []) ? $s : (string) $p['default'];
                    break;
                case self::T_LIST:
                    $items = is_array($v) ? $v : explode(',', (string) $v);
                    $clean = [];
                    foreach ($items as $it) {
                        $it = trim((string) $it);
                        if ($it !== '' && (!isset($p['options']) || array_key_exists($it, $p['options']))) {
                            $clean[$it] = true;
                        }
                    }
                    $out[$k] = implode(',', array_keys($clean));
                    break;
                default: // text / code
                    $out[$k] = is_string($v) ? $v : (string) $v;
            }
        }
        return $out;
    }

    /**
     * 校验一层参数，返回告警列表（不阻断构建）。
     * @return array<int,string>
     */
    public static function validate(string $name, array $params): array
    {
        $warn = [];
        $d = self::get($name);
        if ($d === null) {
            return ["未知层名: {$name}"];
        }
        $known = self::params($name);
        foreach ($params as $k => $v) {
            if (!isset($known[$k])) {
                $warn[] = "[层 {$name}] 未知参数 {$k}（已忽略）";
                continue;
            }
            $p = $known[$k];
            if ($p['type'] === self::T_SELECT && !array_key_exists((string) $v, $p['options'] ?? [])) {
                $warn[] = "[层 {$name}] 参数 {$k} 取值 '{$v}' 不在选项内（已取默认）";
            }
            if ($p['type'] === self::T_INT) {
                if (isset($p['min']) && (int) $v < $p['min']) {
                    $warn[] = "[层 {$name}] 参数 {$k}={$v} 低于下限 {$p['min']}（已夹取）";
                }
                if (isset($p['max']) && (int) $v > $p['max']) {
                    $warn[] = "[层 {$name}] 参数 {$k}={$v} 高于上限 {$p['max']}（已夹取）";
                }
            }
        }
        return $warn;
    }

    /**
     * GUI 用的层清单（按 kind 分组，保持顺序）。
     * @return array<string,array<int,string>>
     */
    public static function grouped(): array
    {
        $groups = ['token' => [], 'shell' => [], 'inject' => []];
        foreach (array_keys(self::all()) as $n) {
            $groups[self::get($n)['kind']][] = $n;
        }
        return $groups;
    }
}
