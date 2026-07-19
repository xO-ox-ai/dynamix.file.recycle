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

1. 在 Unraid 后台打开 **Plugins → Install Plugin**。
2. 粘贴 Releases 中 `dynamix.file.recycle.plg` 的原始 URL。
3. 点击 **Install**。安装完成后 **Tools → Recycle Bin** 页面即出现。
4. 打开 **Settings → Dynamix File Recycle Bin** 启用功能并调整维护策略。

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
