---
name: apifox-openapi-sync
description: 将任意 PHP 项目的 OpenAPI 规范推送到 Apifox 项目。当用户提到「推送接口文档到 Apifox」「同步 OpenAPI」「生成并上传 apifox 文档」「APIFOX_PROJECT_ID / APIFOX_TOKEN 配置」「apifox import」时触发；也适用于需要把 .env 里的 APIFOX_* 配置落到 Apifox 文档的场景。
---

# Apifox OpenAPI 同步（通用）

把项目的 OpenAPI 3 规范推送到指定 Apifox 项目。核心思路：**仅需 `.env` 里的 `APIFOX_*` 配置 + 一个规范文件，运行脚本即推送**。

## 何时使用

- 用户要求「把接口推送到 Apifox」「同步/更新 Apifox 文档」。
- 项目根目录 `.env` 已存在 `APIFOX_PROJECT_ID` / `APIFOX_TOKEN` 等配置。
- 已有或可以生成 OpenAPI 3 的 JSON（如 `scripts/openapi.*.json`，或 Swagger/注解生成的 `openapi.json`）。

## 工作流

1. **确认规范来源**（二选一）：
   - 已有规范文件：记下路径（如 `scripts/openapi.rbac.json`）。
   - 需生成：用 PHP 数组拼出 `openapi`/`info`/`paths`/`components`，`json_encode(..., JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)` 落盘。
2. **读 `.env` 的 `APIFOX_*` 配置**（见下），缺省有兜底。
3. **推送**：调用本 skill 的 `scripts/apifox-sync.php`（已通用化），或项目自带推送脚本。

```bash
# 仅生成/校验规范、探测 CLI，不上传（安全自检）
php <skill>/scripts/apifox-sync.php --spec=scripts/openapi.rbac.json --dry-run

# 正式推送（按 .env 的 APIFOX_* 配置）
php <skill>/scripts/apifox-sync.php --spec=scripts/openapi.rbac.json
```

## `.env` 配置约定（本流程的全部输入）

| 变量 | 含义 | 兜底 |
|------|------|------|
| `APIFOX_PROJECT_ID` | 目标 Apifox 项目 ID | 无（缺失报错） |
| `APIFOX_TOKEN` | CLI `access-token`（`afxp_...`） | 无（缺失报错） |
| `APIFOX_ENABLED` | 总开关，非 `true` 跳过 | `true` |
| `APIFOX_SKIP_UPLOAD` | 只生成/校验不上传 | `false` |
| `APIFOX_MODULE_ID` | `--module-map` 的目标模块 ID | `''`（落到默认模块） |
| `APIFOX_MODULE_TITLE` | 规范的 `info.title`（用于 module-map 键） | 自动读 spec |
| `APIFOX_SPEC_FILE` | 已生成的规范路径 | CLI `--spec` 或自动查找 |
| `APIFOX_IMPORT_MODE` | 文档化保留项（CLI import 实际行为由 `--module-map` 决定） | 忽略 |

> 仅这 6 行（`APIFOX_PROJECT_ID` / `APIFOX_TOKEN` / `APIFOX_ENABLED` / `APIFOX_SKIP_UPLOAD` / `APIFOX_IMPORT_MODE` / 可选 `APIFOX_MODULE_ID`）即可驱动整套推送——脚本读取它们、生成/读取规范、自动定位 `apifox` CLI（Linux `command -v` / Windows `where`），再以 `apifox import --module-map "<title>=<moduleId>"` 推送。

## 关键坑点（必读，避免重复接口/目录）

详见 `references/cli-pitfalls.md`。摘要：

- **必须传 `--module-map "<info.title>=<moduleId>"`**：否则 CLI 会按 `info.title` 新建模块，每次导入都产生一整套重复接口/目录。传入后 CLI 按 `method+path` 匹配**原地更新（merge）**。
- **`tags` 自动建子目录，但不挪旧接口**：openapi 导入靠 `tags` 生成子目录；已存在的接口（按 method+path 匹配）被 `ignore`，**不会**移动其目录。要挪目录用 `apifox endpoint update <id> --file move.json`（`{"folderId":...}`）。
- **`targetEndpointFolderId` 跨模块对新建端点不生效**：新端点会落到默认模块根目录，建完必须用 CLI 挪目录。
- **部分更新优先用 CLI 子命令**：小改动（`apifox endpoint update` / `apifox schema update`）比全量 `import` 安全，不会触碰未涉及的字段。
- **CLI 可用性**：未安装时 `npm i -g apifox-cli`；当前验证版本 `2.2.12`。

## 自测清单

- [ ] `--dry-run`：规范 JSON 能解析、`openapi`/`paths` 存在、CLI 二进制被定位。
- [ ] 缺 `APIFOX_PROJECT_ID`/`APIFOX_TOKEN` 时打印明确报错。
- [ ] `APIFOX_SKIP_UPLOAD=true` 时仅生成不推送。
- [ ] 正式推送后，在 Apifox 项目里核对接口数量无重复、目录归属正确。
