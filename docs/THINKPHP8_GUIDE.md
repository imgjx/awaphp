# ThinkPHP8 项目混淆实战指南

> 适用场景：ThinkPHP8（TP8）应用项目整体混淆——控制器 / 模型 / 服务 / 验证器 / 中间件 / 事件等 `app/` 目录源码。
> 配套示例项目：`longtmp/tp8demo/tp8`（原版）与 `longtmp/tp8demo/tp8_obf`（混淆版），基线对照脚本 `tp8_baseline.sh`，打包用例 `tests/tptest.zip`。
> 验证基线：TP8 示例项目 26 行对照文本与原版**逐字节一致**（`BASELINE SAME 26/26`）。

---

## 1. 设计约束（为什么这样配）

TP8 是重反射、重约定的框架，混淆配置必须守住三条底线：

| 约束 | 含义 | 对策 |
| --- | --- | --- |
| **文件名 / 目录结构保持** | 框架按 `app/controller/Xxx.php` 路径与 PSR-4 自动加载找类，文件改名即崩 | 混淆**只改内容不改路径**，输入输出目录同构 |
| **属性名 / 方法参数名不改** | ORM 字段映射、验证器规则、DI 参数绑定按名字读写（`$request->param('id')`、`Rule::name('title')`） | `--var-keep-props --var-keep-params` 必开 |
| **类名可用性不降级** | `class_exists` / 反射 / `new $service` / 容器绑定按类名字符串命中 | FQCN 感知改名 + `class_alias` 回挂 + classmap 自动加载（见 §4） |

另外：**每个文件独立混淆、同一 salt 即全局一致**——各文件的符号映射由 salt 确定性派生，无需共享中间状态，增量重建天然安全。

---

## 2. 快速开始

```bash
# 输入：TP8 项目的 app/ 目录；输出：同构的混淆目录（文件名保持）
php entry.php \
    --input=/path/to/tp8/app \
    --output=/path/to/tp8_obf/app \
    --level=high \
    --salt=your-project-salt \
    --var-keep-props \
    --var-keep-params
```

完成后：

1. 把混淆产物目录**整体替换**项目里的 `app/`（或按部署环境放置，路径保持一致）；
2. 生成的 `awaphp_autoload.php` 位于输出目录**上一级**（与 `app/` 同层），各产物头部会自动 `require_once __DIR__ . '/../awaphp_autoload.php'`，**不要删除或移动它**；
3. `route/`、`config/`、`public/` 等非 `app/` 目录无需混淆；`route/app.php` 若含闭包路由可保持原样。

> **opcache 注意**：部署机若启用 OPcache 且 `validate_timestamps=1`，替换文件后建议 `opcache_reset()` 或等 `revalidate_freq` 窗口（默认 2s）再验收，避免命中旧编译缓存造成「修了还报错」的假象。

---

## 3. 配置推荐详解

### 3.1 等级选择

| 等级 | 适用 | 要点 |
| --- | --- | --- |
| `--level=mid` | 内部系统、低价值目标 | VM 平坦化 + 函数间接调用，构建快 |
| `--level=high`（**推荐**） | 常规商业交付 | VM 状态机 + 全 P8 方向 + 反调试 / 防 hook / 防篡改，保护与体积平衡 |
| `--level=max` / `--ultra` | 高价值单文件 / 对抗性场景 | 加结构指纹毒化壳（`struct-poison`）；注意壳层更厚，首次加载略慢 |

TP8 示例项目用 `--level=high` 验证通过全部 26 行基线。

### 3.2 必开参数

```bash
--var-keep-props    # 保留属性名（ORM / 验证器 / 视图模型按属性名读写）
--var-keep-params   # 保留方法参数名（TP8 参数绑定 / 命名实参 / 注解按参数名匹配）
--salt=<固定盐>      # 任意非空字符串；同盐 = 跨文件符号一致 + 构建可复现
```

### 3.3 可选增强

```bash
--anti-debug --anti-tamper   # 等级已含时无需重复；单独 --vm 时可显式加
--vm --vm-p8                 # 仅 VM 壳的最小方案（不套等级预设时使用）
--manifest=<path>            # 目录级构建默认生成符号表；增量重建自动复用
```

**函数名混淆**（`func` 层）建议保持默认开启：TP8 里普通函数极少参与反射；但若项目里有 `call_user_func('函数名字符串')` 这类动态调用，动态符号保守排除会自动跳过该名字（§1）。

---

## 4. 类名混淆机制（FQCN 感知）

TP8 的类名混淆不是简单短名替换，而是一条完整链路：

```
源码 app/service/PriceCalculator.php（namespace app\service; class PriceCalculator）
        │  FQCN 感知改名（按 命名空间\短名 全限定粒度）
        ▼
产物 app/service/PriceCalculator.php（namespace app\service; class _fda72ccfe …）
        │  声明点 class_alias('_fda72ccfe' → 'PriceCalculator')  ←— 原名回挂
        │  头部 require_once __DIR__ . '/../awaphp_autoload.php'
        ▼
awaphp_autoload.php（classmap：新名/原名 ↔ 原 rel 路径，双向）
```

要点：

- **文件名保持**：产物仍是 `app/service/PriceCalculator.php`，PSR-4 / 框架自动加载路径不变；
- **classmap 自动加载器**：目录级构建自动生成到输出目录上一级；映射按 `salt` 确定性派生，同 salt 的多次独立构建结果一致；
- **原名可用**：`class_exists('app\service\PriceCalculator')`、反射、字符串类名实例化经 `class_alias` 回挂照常命中；
- **命名空间不改**：`namespace` 声明保持原样，控制器路由（`'Shop/calc' → Shop::calc`）不受影响；
- **VM 兼容**：类保留 VM（ClassPreservingVm）为每个类生成真实类桩（方法体委托 VM 字节码），框架反射方法签名 / 依赖注入照常工作。

### 顶层 return 文件（provider / route / service 注册）

带**顶层 `return 数组`** 的文件（如 `provider.php`、`service.php`、`event.php`）**不套 VM 壳**——VM 会把代码包进 IIFE，顶层 return 值会被吞掉，`App::bind(include …)` 收到 int 直接崩。这类文件仅做 token 级混淆，return 语义逐字节保留。TP8 示例中的 `provider.php` / `service.php` / `event.php` / `middleware.php` 即走此路径。

---

## 5. 运行验证（基线对照）

```bash
# 1) 起原版服务（例：8090）与混淆版服务（例：8091）
php -S 127.0.0.1:8090 -t tp8/public tp8/public/index.php
php -S 127.0.0.1:8091 -t tp8_obf/public tp8_obf/public/index.php

# 2) 各自跑 26 行基线（绝对路径调用！）
bash /path/to/tp8demo/tp8_baseline.sh http://127.0.0.1:8090 /tmp/orig.txt
bash /path/to/tp8demo/tp8_baseline.sh http://127.0.0.1:8091 /tmp/obf.txt

# 3) 逐字节比对
diff /tmp/orig.txt /tmp/obf.txt && echo "BASELINE SAME 26/26"
```

基线覆盖：ThinkPHP8 入口、控制器多参数、模型关联、验证器、事件 / 监听、中间件签名、事务、闭包、`shop/calc`（列表解构 + 生成器 + 收据 heredoc + nullsafe + 调用链）等 26 个对照点。

---

## 6. 已知边界与行为

| 现象 | 原因 | 行为 |
| --- | --- | --- |
| 产物头部有 `require_once .../awaphp_autoload.php` | 类改名需要 classmap | **保留**；它在 declare / namespace 序言之后注入，不影响头部约束 |
| `declare(strict_types=1)` / `namespace` 恒在产物最前 | 序言归一安全网 | 注入器错插进序言区的语句会自动搬回序言之后（否则 PHP 直接致命） |
| 个别复杂语句走 EVAL_STMT 兜底 | VM 暂不支持的语法 | 语句级降级为 `eval`，类引用自动 FQCN 化；`declare` / `namespace` / `use` 不可 eval 的头部语句不进兜底 |
| 构建确定性 | 同 salt 同输入 → 产物逐字节一致（仅 banner 时间戳不同） | 增量重建、CI 缓存友好 |
| 动态调用的函数 / 类不改名 | `call_user_func('f')` 等字符串引用 | 动态符号保守排除，宁可少混淆不可运行时崩 |

---

## 7. 调试钩子（环境变量）

| 变量 | 作用 |
| --- | --- |
| `OBF_VM_SRC=<dir>` | 转储 VM 编译输入（token 层产物）与 CPV 输入，定位「进 VM 前长什么样」 |
| `OBF_CPV_OUT=<dir>` | 转储类保留 VM（ClassPreservingVm）产物 |
| `OBF_VM_DEBUG=1` | 打印 EVAL_STMT 兜底日志（哪条语句、什么原因回退） |
| `OBF_SHELL_DUMP=<dir>` | 非法产物与原始输入落盘 |
| `OBF_LINT_DUMP=<dir>` | `php -l` 失败时转储产物 |

示例：

```bash
OBF_VM_DEBUG=1 php entry.php --input=app --output=out --level=high --salt=x 2>&1 | grep EVAL_FALLBACK
```

---

## 8. FAQ

**Q：要不要混淆 `vendor/`？**
不要。vendor 是三方库，混淆收益低且容易踩兼容；只混淆自己的 `app/`（以及自研扩展目录）。

**Q：可以只混淆部分控制器吗？**
可以，`--input` 指向子目录即可；但注意类改名的 classmap 按**本次构建**生成，多次分开构建请保持**同一 salt**。

**Q：换了 salt 会怎样？**
符号映射全变，必须整目录重建；`awaphp_autoload.php` 同步重新生成。

**Q：为什么 `route/app.php` 不混淆？**
路由文件含大量闭包与框架 DSL，混淆收益低、风险高；且 TP8 路由可在运行期缓存，保持明文便于排查。

**Q：如何做增量重建？**
目录级构建默认写 `.obf-manifest.json` 符号表；同 salt 下重复构建自动复用映射，改动的文件按内容哈希跳过。
