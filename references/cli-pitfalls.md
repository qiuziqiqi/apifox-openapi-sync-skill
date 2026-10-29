# Apifox CLI 推送核心坑点（实战沉淀）

适用于 `apifox-cli`（`npm i -g apifox-cli`），验证版本 **2.2.12**。

## 1. 必须传 `--module-map`，否则无限重复

`apifox import --format openapi` **默认会按规范里的 `info.title` 新建一个模块**。
不传 `--module-map` 的后果：每次导入都产生一整套重复接口 + 重复目录。

```bash
# 错误：每次都新建模块，接口翻倍
apifox import --project <id> --format openapi --file spec.json --access-token <token>

# 正确：把源模块名映射到目标项目既有/默认模块，按 method+path 原地更新（merge）
apifox import --project <id> --format openapi --file spec.json \
  --access-token <token> --module-map "RBAC 鉴权颁发系统 API=8715128"
```

`moduleId` 可用 `apifox module list --project <id>` 查；留空（=默认模块）也能去重。

> 注意：`APIFOX_IMPORT_MODE=merge` 这类 `.env` 变量**不会**被 CLI 识别——merge 行为完全由
> `--module-map` 触发（把源模块映射走，CLI 找不到同名新模块就走匹配更新）。

## 2. `tags` 自动建子目录，但不挪旧接口

openapi 导入走 merge 模式：已存在的接口（按 method+path 匹配）会被 **ignore**（updateCount=0），
**不会更新其所属目录**。因此：

- 想让接口落在某个 `tags` 对应的子目录，必须该接口是**新建**的；
- 已存在的接口要改目录归属，先 `apifox endpoint list` 取 id，再
  `apifox endpoint delete <id>` 删掉，重新 `import` 让 tags 重建目录；
  或直接 `apifox endpoint update <id> --file move.json`（`{"folderId":<目标目录ID>}`）原地挪。

## 3. `targetEndpointFolderId` 跨模块对新建端点不生效

`import-openapi` 的 `targetEndpointFolderId` 只在「默认模块内」生效；跨模块时新建端点
会落到默认模块根目录（folderId=0）。正确做法：先 import（端点落默认模块根），再用
`apifox endpoint update <id> --file move.json` 把端点移入目标目录。

## 4. 目录管理用 CLI，不全靠 import

- 看目录树：`apifox folder list --project <pid> --type endpoint`
- 看端点与 folderId：`apifox endpoint list --page-size 500`
- 挪目录：`apifox endpoint update <id> --file move.json`（`{"folderId":<id>}`，部分更新安全）
- 删重复端点：`apifox endpoint delete <id>`
- 删空目录：`apifox folder delete <id> --type endpoint`

> 写 JSON 参数文件用工作区文件再传容器/本地路径，不要用 shell `echo`（引号/编码易乱）；
> 也不要用 shell `>` 重定向写文件（GBK 乱码）。

## 5. 局部小改动优先用 update 子命令

- 改 schema：`apifox schema update <schemaId> --project <id> --file patch.json`
- 改端点：`apifox endpoint update <id> --project <id> --file patch.json`

数据模型/接口结构先 `get` 完整 JSON，在完整结构上改再 `update`（非 JSON Patch）。

## 6. 网络与重试

`api.apifox.com` 偶发 DNS 失败，重试即可。token 用 `afxp_` 前缀的 access-token。

## 7. CLI 可用性探测

```bash
# Linux / macOS
command -v apifox || command -v apifox-cli
# Windows
where apifox
```
未安装：`npm i -g apifox-cli`。
