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
        '8.2' => 80200, '8.3' => 80300,
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
        $head = "<?php\n"
            . "/* runtime target: PHP {$target} */\n"
            . "if((int)@constant('PHP_VERSION_ID')!==0&&PHP_VERSION_ID<{$id}){"
            . "{$v}=(PHP_VERSION_ID<{$id});@trigger_error('runtime mismatch',E_USER_NOTICE);}\n";
        // 保留原始开标签（若有），避免重复
        $body = preg_replace('/^\s*<\?(php)?\s*/i', '', $code, 1);
        $this->logger->debug("已按目标 PHP {$target} 注入版本兼容外壳");
        return $head . $body;
    }
}
