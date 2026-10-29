# apifox-openapi-sync

一个**可移植**的 AI agent skill：把任意 PHP 项目的 OpenAPI 3 规范推送到指定的 Apifox 项目。
仅凭项目根目录 `.env` 里的 `APIFOX_*` 配置 + 一个规范 JSON，运行脚本即完成推送（幂等 merge，不会重复建模块/接口）。

适用于 CodeBuddy、Claude（`.claude/skills`）、以及任何兼容 `SKILL.md` frontmatter 的 `.agents` 系 agent。

## 它做什么

1. 读取项目 `.env` 的 `APIFOX_*` 配置（无第三方依赖，纯 PHP 自己解析）。
2. 定位并校验 OpenAPI 规范 JSON（默认 `openapi.json` / `scripts/openapi*.json`，可用 `--spec` 指定）。
3. 跨平台探测 `apifox` CLI（Linux `command -v` / Windows `where`）。
4. 调用 `apifox import --module-map "<info.title>=<moduleId>"` 推送，**按 method+path 原地更新**，避免重复。

## 安装到各 agent

把本仓库**整个目录**放到对应 agent 的 skills 目录下（目录名即 skill 名）：

```bash
# CodeBuddy（用户级，全项目可用）
~/.codebuddy/skills/apifox-openapi-sync/

# CodeBuddy（项目级）
<project>/.codebuddy/skills/apifox-openapi-sync/

# Claude
~/.claude/skills/apifox-openapi-sync/

# 其它兼容 .agents 的 agent
~/.agents/skills/apifox-openapi-sync/
```

复制后 agent 在检测到「推送接口到 Apifox / 同步 OpenAPI / APIFOX_PROJECT_ID 配置」等意图时会自动加载。

## 使用

```bash
# 安全自检：仅生成/校验规范、探测 CLI，不上传
php apifox-openapi-sync/scripts/apifox-sync.php --spec=scripts/openapi.rbac.json --dry-run

# 正式推送（按 .env 的 APIFOX_* 配置）
php apifox-openapi-sync/scripts/apifox-sync.php --spec=scripts/openapi.rbac.json
```

脚本会自动在「当前运行目录」和「脚本所在项目根」查找 `.env`；也可用 `--env=<path>` 显式指定。

## `.env` 配置约定（本流程的全部输入）

| 变量 | 含义 | 兜底 |
|------|------|------|
| `APIFOX_PROJECT_ID` | 目标 Apifox 项目 ID | 无（缺失报错） |
| `APIFOX_TOKEN` | CLI `access-token`（`afxp_...`） | 无（缺失报错） |
| `APIFOX_ENABLED` | 总开关，非 `true` 跳过 | `true` |
| `APIFOX_SKIP_UPLOAD` | 只生成/校验不上传 | `false` |
| `APIFOX_MODULE_ID` | `--module-map` 目标模块 ID | `''`（落到默认模块） |
| `APIFOX_MODULE_TITLE` | 规范的 `info.title`（module-map 键） | 自动读 spec |
| `APIFOX_SPEC_FILE` | 已生成的规范路径 | `--spec` 或自动查找 |
| `APIFOX_IMPORT_MODE` | 文档化保留项（CLI 实际行为由 `--module-map` 决定） | 忽略 |

## 前置依赖

- PHP 7.4+
- 宿主机安装 Apifox CLI：`npm i -g apifox-cli`（验证版本 2.2.12）
- 在 Apifox 项目里拿到 `APIFOX_PROJECT_ID` 与个人访问令牌（项目设置 → API 访问）

## 关键坑点

详见 `references/cli-pitfalls.md`：

- **必须传 `--module-map`**，否则每次导入都按 `info.title` 新建模块、产生整套重复接口。
- `tags` 自动建子目录，但**不会**挪动已存在接口的目录；要挪目录用 `apifox endpoint update <id> --file move.json`。
- 局部小改动优先用 `apifox endpoint update` / `apifox schema update`，别全量 `import`。
- `targetEndpointFolderId` 跨模块对新建端点不生效，新建端点会落默认模块根目录，需 CLI 挪目录。

## 许可证

MIT
