# Dynamix File Recycle Bin 文件回收站

> 🇬🇧 English version: [README.md](README.md)

为 Unraid **Dynamix File Manager（DFM）** 文件浏览器打造的**安全回收站插件**。
本插件不再"永久删除"文件，而是在 DFM 文件浏览器的每一行旁添加一个独立的
**"移入回收站"** 按钮，将文件或文件夹移动到对应盘/dataset 的 `.RecycleBin`
目录中，方便随时浏览、还原或按策略自动清理。

- ✅ 在 DFM Browse 页面注入按钮，**不修改任何 Unraid 核心文件**（使用官方
  `Menu='Buttons'` 注入通道）。
- ✅ 每个卷一个回收站。v1 版本支持 `/mnt/disk*` 普通数据盘与独立的 ZFS
  dataset（v1 暂不支持 `/mnt/user` 与 `/mnt/cache*`）。
- ✅ 灵活的维护策略：按天年龄淘汰、按容量阈值（LRU）淘汰、可选定时清空、
  日志级别与日志保留时长。
- ✅ 界面与文档均为**中英双语**，跟随 Unraid 系统语言切换。
- ✅ 符合 Unraid 7.x 的 CSRF 验证要求（依赖官方 `auto_prepend` 校验，插件
  本身不重复实现 CSRF）。

## 环境要求

| 组件 | 版本 |
|---|---|
| Unraid OS | 7.3.2 及以上 |
| PHP | 8.x（Unraid 自带） |
| Dynamix File Manager | 已安装（Unraid 7.3+ 自带） |
| 可选 | 使用 ZFS dataset 时需要 `zfs` 工具 |

## 安装

下面两种方式任选其一。两种方式使用的插件 URL 相同：

```
https://github.com/xO-ox-ai/dynamix.file.recycle/releases/download/v2026.07.19a/dynamix.file.recycle.plg
```

> 请始终从 [Releases 页面](https://github.com/xO-ox-ai/dynamix.file.recycle/releases)
> 复制**对应版本**的 URL。上面这条链接指向最新已发布版本。

### 方式 A — 通过 Unraid 网页后台安装（推荐）

1. 打开 **Plugins → Install Plugin**。
2. 将 Releases 页面中 `.plg` 的 URL 粘贴到输入框。
3. 点击 **Install**。插件管理器会自动下载 `.txz` 包、校验 MD5、解包并执行
   安装钩子（注册 cron、初始化 SQLite、复制默认配置）。
4. 安装完成后 **Tools → Recycle Bin** 页面即出现，**Plugins** 列表中也会
   新增本插件条目，便于日后升级。
5. 打开 **Settings → Dynamix File Recycle Bin** 启用功能并调整维护策略。

### 方式 B — 通过 Unraid 命令行安装

适合无界面服务器或脚本化部署。

```bash
# 1. 先把 .plg 描述文件下载到服务器。
wget -O /tmp/dynamix.file.recycle.plg \
  https://github.com/xO-ox-ai/dynamix.file.recycle/releases/download/v2026.07.19a/dynamix.file.recycle.plg

# 2. 交给插件管理器执行。其行为与网页后台完全一致
#    （下载 .txz → 校验 MD5 → 解包 → 执行钩子）。
/usr/local/emhttp/plugins/dynamix/scripts/plugin install /tmp/dynamix.file.recycle.plg
```

> 若你的 Unraid 版本不在该路径暴露 `plugin install` 命令，请使用网页方式
> 安装；最终安装结果一致。

## 卸载

插件默认**保留数据**：各卷下的 `.RecycleBin/` 目录以及
`/boot/config/plugins/dynamix.file.recycle/` 中的设置都会保留，方便日后
重装不丢数据。

### 方式 A — 通过 Unraid 网页后台卸载（推荐）

1. 打开 **Plugins**，在列表中找到 **Dynamix File Recycle Bin**。
2. 点击 **齿轮图标 → Remove Plugin**（或 **Uninstall**）。
3. 确认卸载。卸载钩子会移除 cron 任务、删除插件代码与临时状态
   （SQLite / 日志）。
4. *（可选）* 如需彻底清除数据与设置，执行下面的手动清理命令。

### 方式 B — 通过 Unraid 命令行卸载

```bash
# 1. 执行插件自带的卸载钩子（与插件管理器使用的路径一致）。
PLUGIN_DIR="/usr/local/emhttp/plugins/dynamix.file.recycle"
if [ -x "$PLUGIN_DIR/scripts/remove.sh" ]; then
    "$PLUGIN_DIR/scripts/remove.sh"
else
    echo "插件目录不存在 —— 无需通过钩子卸载。"
fi

# 2. （可选）清除保留的数据：删除所有卷下的 .RecycleBin/ 目录。
#    建议先查看一遍再删除：
find /mnt -maxdepth 4 -type d -name .RecycleBin -print
#    rm -rf $(find /mnt -maxdepth 4 -type d -name .RecycleBin)

# 3. （可选）清除 /boot 下的持久设置（重启后仍然存在）。
rm -rf /boot/config/plugins/dynamix.file.recycle
```

## 使用

- 打开 **Main → Browse**（Dynamix File Manager）。每行右侧多出一个按钮，
  点击即可将该条目移入对应卷的回收站。
- 打开 **Tools → Recycle Bin** 浏览、还原或永久删除回收站中的文件。
- 打开 **Settings → Dynamix File Recycle Bin** 切换总开关、维护策略、日志
  级别、历史记录与语言。

## 回收站目录长什么样？

| 原路径 | 回收站位置 |
|---|---|
| `/mnt/disk1/Movies/x.mkv` | `/mnt/disk1/.RecycleBin/Movies/x.mkv` |
| `/mnt/tank/photos/2025/a.jpg`（ZFS） | `/mnt/tank/.RecycleBin/photos/2025/a.jpg` |

原 owner/group/mode 会被完整保留，确保还原后文件属性一致。

## 安全性

- 所有写操作均要求**管理员**登录。
- 所有路径会被规范化并限制在原卷内，禁止 `..` 跨盘跳出。
- 跨文件系统移动时回退为 `cp -a` + `rm`，确保正确性（仅当副本写入成功
  后才删除原文件）。

## 文档

- [English README](README.md)
- [设计文档](docs/DESIGN.md)
- [更新日志](CHANGELOG.md)

## License

MIT — 详见 [LICENSE](LICENSE)。
