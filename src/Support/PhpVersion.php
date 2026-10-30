<?php
/**
 * 目标 PHP 版本兼容开关（PhpVersion）
 *
 * 职责：
 *  - 记录目标运行版本（如 7.4 / 8.0 / 8.1 / 8.2 / 8.3）。
 *  - 为其它模块提供"是否可使用现代语法"的判断，避免生成目标版本不支持的写法。
 *  - 在产物头部注入目标版本声明，并提供运行期版本断言（不兼容时仅做无害提示）。
 *
 * 说明：本混淆器生成物默认仅使用 7.4+ 通用语法，因此该模块主要起"开关/约束/反检测"
 * 作用，而非真正改写语义。
 */

declare(strict_types=1);

final class PhpVersion
{
    /** @var array<string,int> */
    private static array $map = [
        '7.4' => 70400, '8.0' => 80000, '8.1' => 80100,
        '8.2' => 80200, '8.3' => 80300, '8.4' => 80400, '8.5' => 80500,
    ];

    private string $target = '8.1';
    private int $targetId = 80100;

    public function __construct(private Logger $logger, private ?Salt $salt = null) {}

    public function setTarget(string $v): void
    {
        $v = trim($v);
        if (isset(self::$map[$v])) {
            $this->target = $v;
            $this->targetId = self::$map[$v];
        } else {
            $this->logger->warn("未知目标 PHP 版本 '{$v}'，回退到 8.1");
        }
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function targetId(): int
    {
        return $this->targetId;
    }

    /** 是否允许使用 8.0+ 语法（命名参数 / 联合类型 / match 等） */
    public function isModern(): bool
    {
        return $this->targetId >= 80000;
    }

    /** 是否允许 8.1+ 语法（enum / 只读属性等） */
    public function is81(): bool
    {
        return $this->targetId >= 80100;
    }

    /**
     * 注入目标版本声明 + 运行期版本提示。
     * 注意：不强制阻断，仅在不兼容时写入一条易混淆的注释式提示（不影响执行）。
     */
    public function wrap(string $code): string
    {
        $target = $this->target;
        $id = $this->targetId;
        // 拟真化：注释不带工具品牌（原 "awa-obf target" 属于一眼识别的指纹），
        // 变量名由 NameStyle 从业务词池生成（原 $__awa_vc 直接泄露"这是混淆壳"）。
        $style = new NameStyle($this->salt ?? new Salt(''));
        $v = $style->varName('php-version-check');
        $stmt = "if((int)@constant('PHP_VERSION_ID')!==0&&PHP_VERSION_ID<{$id}){"
            . "{$v}=(PHP_VERSION_ID<{$id});@trigger_error('runtime mismatch',E_USER_NOTICE);}";
        $body = preg_replace('/^\s*<\?(php)?\s*/i', '', $code, 1);
        $this->logger->debug("已按目标 PHP {$target} 注入版本兼容外壳");

        // 插入点计算（满足 PHP 语法约束，避免破坏首条语句限制）：
        //  1) `declare(strict_types=1)` 必须是文件首条语句（注释可以，语句不行）→ 跳过它；
        //  2) 命名空间声明（`namespace X { }` 或 `namespace X;`）必须是 declare 之后的首条
        //     语句 → 若紧跟 namespace，则插入点继续后移到命名空间声明之后。
        // 这样守卫语句落在合法位置：不早于 declare、不早于命名空间声明。
        $at = $this->insertionOffset($body);
        return "<?php\n/* runtime target: PHP {$target} */\n"
            . substr($body, 0, $at) . "\n" . $stmt . "\n" . substr($body, $at);
    }

    /**
     * 计算版本守卫的合法插入点（$body 内字节偏移）。
     *
     * PHP 的两条硬约束：
     *   - `declare(strict_types=1)` 必须是文件首条语句（注释/空白不算语句）；
     *   - `namespace` 声明必须是 declare 之后的首条语句。
     * 因此守卫只能落在"declare 之后 + namespace 声明之后"。
     *
     * 必须按 token 扫描而非正则锚定：混淆后文件头部常常先出现注释
     * （如互斥注释 / 反 LLM 伪造 CHANGELOG），正则 `\A\s*declare` 会直接失配，
     * 于是守卫被插到 declare/namespace 之前 → "must be the very first statement" 致命错误。
     */
    private function insertionOffset(string $body): int
    {
        $plen = 6; // "<?php " 前缀长度
        $tokens = token_get_all('<?php ' . $body);
        $n = count($tokens);
        $pos = 0;   // 在 "<?php " . $body 中的字节偏移
        $at = 0;    // 已确认安全的插入点（$body 内偏移）
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $id = is_array($t) ? $t[0] : null;
            $tx = is_array($t) ? (string) $t[1] : (string) $t;
            // 开标签 / 空白 / 注释：都不算语句，跳过
            if ($id === T_OPEN_TAG || $id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                $pos += strlen($tx);
                continue;
            }
            if ($id === T_DECLARE) {
                $pos += strlen($tx);
                for ($j = $i + 1; $j < $n; $j++) {
                    $u = $tokens[$j];
                    $ux = is_array($u) ? (string) $u[1] : (string) $u;
                    $pos += strlen($ux);
                    $i = $j;
                    if (!is_array($u) && $ux === ';') {
                        break;
                    }
                }
                $at = max(0, $pos - $plen);
                continue;
            }
            if ($id === T_NAMESPACE) {
                $pos += strlen($tx);
                for ($j = $i + 1; $j < $n; $j++) {
                    $u = $tokens[$j];
                    $ux = is_array($u) ? (string) $u[1] : (string) $u;
                    $pos += strlen($ux);
                    $i = $j;
                    if (!is_array($u) && ($ux === ';' || $ux === '{')) {
                        break;
                    }
                }
                $at = max(0, $pos - $plen);
                break;
            }
            break; // 首个真正的语句：插入点定在此前
        }
        return min($at, strlen($body));
    }
}
