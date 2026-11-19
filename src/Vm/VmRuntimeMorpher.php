<?php
/**
 * VM 解释器本体混淆（VmRuntimeMorpher / v1.2.6）
 *
 * 此前 VM 保护的是【用户代码】，解释器自身虽已随机化变量名 / 打乱 case / 插入诱饵 case，
 * 但源码形态仍有稳定指纹：清一色十进制常量、可读的基元字符串、
 * 结构规整的闭包序列。本模块在解释器 PHP 生成完毕后做一次后处理：
 *
 *   1. **常量形态重写**：所有十进制整数字面量改写成十六进制（`100003 → 0x186a3`），
 *      满足条件的 ASCII 单引号串改写成 `\xHH` 转义串。两者都是 PHP **编译期常量**，
 *      opcode 完全相同、运行期零成本，但文本指纹与字节码常量池的直观可读性被抹掉。
 *   2. **不可达伪逻辑**：在解释器闭包序列中插入若干「看似密钥调度 / 校验表构建」的块，
 *      条件恒假（strlen(串) < 0），永不执行、不定义函数、不写状态；
 *      其存在只为让解释器的控制流图多出几条永远走不到的分支。
 *   3. **误导性注释**：注释形态伪装成兼容层 / 历史路径说明，干扰基于注释摘要的分析。
 *
 * 安全边界（绝不改炸）：
 *   - 只动 T_LNUMBER / T_CONSTANT_ENCAPSED_STRING 两类字面量，且都做等值变换；
 *   - 字符串只转「纯 ASCII、无反斜杠、无单引号、无美元符」的单引号串 —— 这类串在
 *     单双引号下语义一致，`\xHH` 逐字节还原，不含插值与转义歧义；
 *   - 超长串、含非 ASCII 字节的串（多字节 UTF-8）、已带 0x 的数字一律跳过；
 *   - 伪逻辑用独立随机名，且整体包在恒假条件里，对 opcache / JIT 的热路径无影响。
 */

declare(strict_types=1);

final class VmRuntimeMorpher
{
    private Salt $salt;
    private bool $logicExpr;
    private ?IdentityExpr $idExpr = null;
    private int $idSeed = 0;

    /**
     * @param bool $logicExpr v1.2.8：是否用 Logic 恒等式混淆解释器里的整数字面量。
     *                       开启时十进制整数 → ((Ka)^(Kb)) 纯位运算常量表达式（case 标签/
     *                       数组索引/默认参数均合法、编译期求值零运行成本）；关闭时退回
     *                       v1.2.6 的纯十六进制等值重写。
     */
    public function __construct(Salt $salt, bool $logicExpr = true)
    {
        $this->salt = $salt;
        $this->setLogicExpr($logicExpr);
    }

    /** 运行时切换 Logic 恒等式混淆（buildArtifact 依据 opts 调用）。 */
    public function setLogicExpr(bool $on): void
    {
        $this->logicExpr = $on;
        if ($on) {
            if (!class_exists(IdentityExpr::class, false)) {
                require_once __DIR__ . '/../AntiAst/IdentityExpr.php';
            }
            $this->idExpr = new IdentityExpr();
            $this->idSeed = $this->salt->isEmpty()
                ? 0
                : (int) hexdec(substr(bin2hex($this->salt->derive('vm-logic-expr', 4)), 0, 7));
            $this->idExpr->setSeed($this->idSeed);
        } else {
            $this->idExpr = null;
        }
    }

    /**
     * 生成插到解释器闭包序列中的不可达伪逻辑（在占位符替换之前调用）。
     * 块内不使用任何外部变量，名字由 salt 独立派生，不与真实解释器变量冲突。
     */
    public function fakeBlocks(): string
    {
        $out = '';
        for ($b = 0; $b < 3; $b++) {
            $tbl = $this->name('tbl' . $b);
            $acc = $this->name('acc' . $b);
            $tmp = $this->name('tmp' . $b);
            $vals = [];
            for ($i = 0; $i < 8; $i++) {
                $vals[] = '0x' . dechex($this->salt->deriveInt('vm-morph-blk' . $b . '-' . $i, 1, 0x7fffffff));
            }
            $tag = 'vm_' . substr(bin2hex($this->salt->derive('vm-morph-tag' . $b, 4)), 0, 8);
            // strlen(字面量) < 0 恒假：编译期即可判定类型，运行期一次 strlen + 比较。
            $out .= $tbl . '=[' . implode(',', $vals) . '];'
                . $acc . '=0x' . dechex($this->salt->deriveInt('vm-morph-seed' . $b, 1, 0xffff)) . ';'
                . 'if(strlen(\'' . $tag . '\')<0){foreach(' . $tbl . ' as ' . $tmp . '){'
                . $acc . '=(' . $acc . '*0x21+' . $tmp . ')&0xffffffff;}'
                . $this->name('stub' . $b) . '(' . $acc . ');}'
                . '/* 兼容 shim：保留旧派发路径，正常执行不会进入上方校验分支 */';
        }
        return $out;
    }

    /**
     * token 级常量形态重写。输入输出都是【完整的 PHP 解释器源码】。
     */
    public function tokenMorph(string $php): string
    {
        $tokens = @token_get_all($php);
        if (!is_array($tokens)) {
            return $php; // 解析失败就原样返回（宁可不混淆）
        }
        $out = '';
        $prevSig = null; // 上一个有效 token（用于识别 break/continue 层级操作数）
        foreach ($tokens as $t) {
            if (!is_array($t)) {
                $out .= $t;
                if (!Parser::isWhitespaceOrComment($t)) {
                    $prevSig = $t;
                }
                continue;
            }
            if ($t[0] === T_LNUMBER) {
                $text = (string) $t[1];
                // 跳过已是 0x/0b/0o 形态、带下划线、或超出 32 位安全区的数字
                if (preg_match('/^0[xXbBoO]/', $text) || str_contains($text, '_')) {
                    $out .= $text;
                    $prevSig = $t;
                    continue;
                }
                if (ctype_digit($text) && strlen($text) <= 10) {
                    $v = (int) $text;
                    if ($v >= 0 && $v <= 0x7fffffff) {
                        // break/continue 的层级操作数必须是整数字面量，不能改写成表达式
                        $isJumpLevel = is_string($prevSig)
                            ? false
                            : (is_array($prevSig) && in_array($prevSig[0], [T_BREAK, T_CONTINUE], true));
                        if ($isJumpLevel) {
                            $out .= $text; // 保持裸字面量
                            $prevSig = $t;
                            continue;
                        }
                        if ($this->logicExpr && $this->idExpr !== null) {
                            // v1.2.8：Logic 恒等式混淆——纯位运算常量表达式，零运行成本、
                            // case 标签/数组索引/默认参数均合法（已验证），语义逐字节相等。
                            $expr = $this->idExpr->intExprConstSafe($text, $this->idSeed);
                            $t[1] = $expr ?? ('0x' . dechex($v)); // 恒等式失败则退回十六进制
                        } else {
                            $t[1] = '0x' . dechex($v);
                        }
                    }
                }
            } elseif ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $text = (string) $t[1];
                $t[1] = $this->morphString($text);
            }
            $out .= Parser::text($t);
            $prevSig = $t;
        }
        return $out;
    }

    /**
     * 单引号 ASCII 串 → 双引号 \xHH 串（编译期常量，逐字节等值）。
     * 含 $ / \ / ' / 非 ASCII / 换行 / 超长的串一律原样保留。
     */
    private function morphString(string $lit): string
    {
        if (strlen($lit) < 3 || $lit[0] !== "'" || substr($lit, -1) !== "'") {
            return $lit;
        }
        $inner = substr($lit, 1, -1);
        if ($inner === '' || strlen($inner) > 64
            || preg_match('/[\\\\$\'\\r\\n\\t\\x80-\\xff]/', $inner)) {
            return $lit;
        }
        // 只转「看起来像标识符/短标记」的串，避免把 SQL/正则等语义敏感串改写
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\-\\/:.]*$/', $inner)) {
            return $lit;
        }
        $hex = '';
        $n = strlen($inner);
        for ($i = 0; $i < $n; $i++) {
            $hex .= '\\x' . bin2hex($inner[$i]);
        }
        return '"' . $hex . '"';
    }

    private function name(string $label): string
    {
        return '$_m' . substr(bin2hex($this->salt->derive('vm-morph-name|' . $label, 6)), 0, 10);
    }
}
