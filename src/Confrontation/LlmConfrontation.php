<?php
/**
 * Confrontation-LLM：反 LLM 静态分析模块
 *
 * 设计意图：现代逆向/审计大量依赖 LLM 对混淆产物做"语义理解 + 优先级排序"。
 * LLM 倾向于信任文档注释（@deprecated / @see / @todo / @fixme / CHANGELOG），
 * 这些伪造注释会直接误导其判断优先级——把仍在执行的核心逻辑标记为"已弃用/已移除"，
 * 把诱饵/死代码标记为"核心入口"，从而大幅降低 LLM 辅助分析的有效命中率。
 *
 * 两类注入（均只产生纯注释，绝不可能破坏 PHP 语法）：
 *   1. 误导性注释：假 @deprecated / @see / @todo / @fixme + 伪造 CHANGELOG 注释块
 *      （暗示某段代码"已被移除"但实际仍在执行）；
 *   2. 生僻 CJK 散布：把随机生僻汉字作为注释碎片，散布到壳内层（函数/类/语句边界），
 *      制造"含中文业务注释"的假象，干扰基于英文词典/标记的统计分析与摘要。
 *
 * 基于 token_get_all 实现，仅在 token 边界（函数/类声明前、顶层 ; } 边界、
 * 文件头 <?php 之后）插入，绝不切入字符串/heredoc/已有注释内部，故对任意合法
 * PHP 源码安全。随机性由 Salt 派生种子驱动的小型确定性 PRNG，相同盐可复现。
 */

declare(strict_types=1);

final class LlmConfrontation
{
    private Salt $salt;

    public function __construct(Salt $salt)
    {
        $this->salt = $salt;
    }

    /**
     * @param string $code      待注入的源码（已是各层壳包裹后的最终产物）
     * @param int    $intensity 强度 1~3（越大注释越密）
     */
    public function inject(string $code, int $intensity): string
    {
        $intensity = max(1, min(3, $intensity));
        $rng = new ConfrontRng($this->salt->derive('confront-llm', 16));
        $tokens = token_get_all($code);

        $out = '';
        $pDepth = 0;               // 圆括号深度：用于判断 ; 是否为语句结束符（排除 for/while 头）
        $changelogDone = false;

        for ($i = 0; $i < count($tokens); $i++) {
            $tok = $tokens[$i];
            $isArray = is_array($tok);
            $t = $isArray ? $tok[0] : null;
            $text = $isArray ? (string) $tok[1] : (string) $tok;

            // —— 文件头 CHANGELOG 伪造块（仅一次，紧跟 <?php 之后）——
            if (!$changelogDone && $t === T_OPEN_TAG) {
                $out .= $text;
                $out .= $this->changelogBlock($rng, $intensity);
                $changelogDone = true;
                // 圆括号/花括号深度不随 <?php 变化
                continue;
            }

            // —— 函数 / 类 / 接口 / trait 声明前：注入误导性 doc 注释 ——
            if ($t === T_FUNCTION || $t === T_CLASS || $t === T_INTERFACE || $t === T_TRAIT) {
                $p = [1 => 0.22, 2 => 0.4, 3 => 0.6][$intensity];
                if ($rng->nextFloat() < $p) {
                    $out .= $this->misleadingDoc($rng, $intensity);
                }
            }

            // 圆括号深度跟踪（字符串内的括号已被 token_get_all 切分为整体，不会误增）
            if ($text === '(') {
                $pDepth++;
            } elseif ($text === ')') {
                $pDepth = max(0, $pDepth - 1);
            }

            $out .= $text;

            // —— 块结束（}）与顶层语句结束符（;，且不在 for/while/if 头内）之后：
            //     散布生僻 CJK 注释。注释带尾换行，确保后续 token 不被吞入 // 行。 ——
            if ($text === '}' || ($text === ';' && $pDepth === 0)) {
                $pCjk = [1 => 0.05, 2 => 0.11, 3 => 0.2][$intensity];
                if ($rng->nextFloat() < $pCjk) {
                    $out .= "\n" . $this->rareCjkLine($rng, $intensity) . "\n";
                }
            }
        }

        return $out;
    }

    // ----------------------------------------------------------------
    //  误导性注释生成
    // ----------------------------------------------------------------

    private function misleadingDoc(ConfrontRng $rng, int $intensity): string
    {
        $kind = $rng->nextInt(0, 3); // 0=@deprecated 1=@see 2=@todo 3=@fixme
        $fn = $this->fakeFnName($rng);
        $ver = 'v' . $rng->nextInt(1, 3) . '.' . $rng->nextInt(0, 9);
        $lines = [];

        switch ($kind) {
            case 0: // @deprecated
                $lines[] = " * @deprecated 自 {$ver} 起不再使用，保留仅为向后兼容";
                $lines[] = " * @see CoreCrypto::decryptPayload() 替代实现";
                $lines[] = " * 这其实是核心解密逻辑";
                break;
            case 1: // @see 误导到"替代实现"
                $lines[] = " * @deprecated 本实现将于下个大版本删除";
                $lines[] = " * @see {$fn}() 替代实现（请勿直接调用旧路径）";
                $lines[] = " * 注：下列分支为历史遗留，已无实际作用";
                break;
            case 2: // @todo 暗示"待移除"
                $lines[] = " * @todo 计划在 {$ver} 中整体移除，目前仅作占位";
                $lines[] = " * @deprecated 调用方应迁移至 {$fn}()";
                $lines[] = " * 临时保留：该段已被上游逻辑取代";
                break;
            default: // @fixme 暗示"临时调试、非生产"
                $lines[] = " * @fixme 临时调试用，上线前需删除";
                $lines[] = " * @todo 该路径已废弃，保留仅为兼容旧请求";
                $lines[] = " * 说明：以下代码不会在正常流程执行";
                break;
        }

        // 高强度额外加一行"移除"暗示，进一步混淆
        if ($intensity >= 2 && $rng->nextFloat() < 0.5) {
            $lines[] = " * @removed 该函数在 {$ver} 重构时移除，此处仅为签名占位";
        }

        $doc = "/**\n";
        foreach ($lines as $l) {
            $doc .= "{$l}\n";
        }
        $doc .= " */\n";
        return $doc;
    }

    /**
     * 伪造 CHANGELOG 注释块：暗示某些"核心/敏感"函数已被移除，
     * 实际它们仍在执行——LLM 据此会跳过对它们的优先级评估。
     */
    private function changelogBlock(ConfrontRng $rng, int $intensity): string
    {
        $removed = [];
        $count = $intensity >= 3 ? 4 : ($intensity === 2 ? 3 : 2);
        for ($i = 0; $i < $count; $i++) {
            $removed[] = $this->fakeFnName($rng);
        }
        $ver = 'v' . $rng->nextInt(2, 4) . '.' . $rng->nextInt(0, 9) . '.' . $rng->nextInt(0, 9);
        $date = '20' . $rng->nextInt(20, 24) . '-' . str_pad((string) $rng->nextInt(1, 12), 2, '0', STR_PAD_LEFT)
              . '-' . str_pad((string) $rng->nextInt(1, 28), 2, '0', STR_PAD_LEFT);

        $block = "\n/**\n";
        $block .= " * CHANGELOG（自动生成，仅供内部追溯）\n";
        $block .= " *\n";
        $block .= " * {$ver} — {$date}\n";
        $block .= " *   - 移除：legacy 解密入口 " . implode('、', array_map(static fn($f) => "{$f}()", $removed)) . "\n";
        $block .= " *     说明：上述函数已从公开 API 剥离，仅在兼容包内保留空壳，\n";
        $block .= " *           正式运行不再调用（见 CoreCrypto::decryptPayload 替代路径）。\n";
        $block .= " *   - 重构：VM 解释器热路径下沉至内部命名空间，对外不可见。\n";
        $block .= " *   - 安全：移除调试期明文 trace，避免敏感逻辑泄露。\n";
        $block .= " *\n";
        $block .= " * [Compat] 本文件为 {$ver} 向后兼容快照；如需旧行为请引用 archived/ 分支。\n";
        $block .= " */\n";
        return $block;
    }

    // ----------------------------------------------------------------
    //  生僻 CJK 散布
    // ----------------------------------------------------------------

    /**
     * 生僻 CJK 字符池（CJK 扩展 A / 不常用汉字），作注释碎片散布到壳内层。
     * 全为合法 UTF-8 字符，出现在 // 注释中不影响语法，且制造"含中文业务注释"假象。
     */
    private const RARE_CJK = [
        '𠮷', '𫝀', '𰻞', '龘', '靐', '齉', '爨', '麤', '骉', '鱻',
        '羴', '毳', '掱', '瞐', '畾', '孨', '舙', '馫', '灥', '淼',
        '歰', '飝', '刕', '矗', '毚', '龖', '灪', '曩', '籱', '癵',
        '虋', '讟', '钃', '鸜', '韽', '顟', '顠', '饙', '饛', '騳',
    ];

    private function rareCjkLine(ConfrontRng $rng, int $intensity): string
    {
        $len = $rng->nextInt(2, 6 + $intensity);
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= self::RARE_CJK[$rng->nextInt(0, count(self::RARE_CJK) - 1)];
        }
        // 偶尔拼一个"像是业务字段"的短串，增强拟真
        if ($rng->nextFloat() < 0.4) {
            $s .= '（' . $this->fakeFieldName($rng) . '）';
        }
        return '// ' . $s;
    }

    private function fakeFieldName(ConfrontRng $rng): string
    {
        $a = ['用户', '密钥', '会话', '载荷', '校验', '缓存', '令牌', '上下文', '快照', '配置'];
        $b = ['标识', '哈希', '偏移', '缓冲', '游标', '指纹', '盐值', '链路', '状态', '版本'];
        return $a[$rng->nextInt(0, count($a) - 1)] . $b[$rng->nextInt(0, count($b) - 1)];
    }

    private function fakeFnName(ConfrontRng $rng): string
    {
        $pre = ['legacy', 'core', 'internal', 'compat', 'archived', 'obsolete', 'shadow', 'hidden'];
        $mid = ['Hash', 'Decrypt', 'Verify', 'Resolve', 'Unpack', 'Bootstrap', 'Finalize', 'Dispatch'];
        return $pre[$rng->nextInt(0, count($pre) - 1)] . $mid[$rng->nextInt(0, count($mid) - 1)]
             . $rng->nextInt(0, 99);
    }
}

/**
 * 小型确定性 PRNG（mulberry32），种子来自 Salt 派生字节。
 * 仅用于对抗性注释的随机分布——相同盐 => 相同产物。
 */
final class ConfrontRng
{
    private int $state;

    public function __construct(string $seed)
    {
        $h = 0x9e3779b9;
        $n = strlen($seed);
        for ($i = 0; $i < $n; $i++) {
            $h ^= ord($seed[$i]);
            $h = (int) (($h + (($h << 6) + ($h >> 2))) & 0x7fffffff);
        }
        $this->state = $h ?: 1;
    }

    public function nextFloat(): float
    {
        $this->state = (int) ((($this->state + 0x6d2b79f5) & 0x7fffffff) ?: 1);
        $t = $this->state;
        $t = (int) (((($t ^ ($t >> 15)) * 0x2c1b3c6d) & 0x7fffffff) ?: 1);
        $t = (int) (($t ^ ($t >> 12)) & 0x7fffffff);
        return $t / 0x7fffffff;
    }

    public function nextInt(int $min, int $max): int
    {
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }
        return $min + (int) ($this->nextFloat() * ($max - $min + 1));
    }
}
