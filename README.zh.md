# Dynamix File Recycle Bin 文件回收站

> English documentation: [README.md](README.md)

这是一个面向 Unraid Dynamix File Manager（DFM）的受保护回收站插件。它会在
DFM 原生**删除**按钮右侧增加一个跟随勾选状态的**移入回收站**按钮，并通过同一
文件系统内的原子重命名，把选中的文件或目录移入所属卷的 `.RecycleBin`。

当前版本刻意只处理简单且能够验证的存储结构。确认前后端会检查每个选中项目，
任何路径不受支持都会显示明确原因和建议。

## 支持范围

- Unraid 阵列挂载点（例如 `/mnt/disk1`）中的普通文件和目录。
- 底层设备可以确认不是 USB、不是可移动介质的本地 ZFS dataset。每个 dataset
  分别拥有自己的 `.RecycleBin` 和 SQLite 数据库。
- 只允许同一文件系统内的原子重命名。

以下情况会被明确拒绝：

- `/mnt/user`、`/mnt/user0` 虚拟用户共享路径。
- `/mnt/cache*` 以及其他缓存盘/存储池路径。
- `/mnt/disks`（Unassigned Devices）、`/mnt/remotes`、远程文件系统和任意外置挂载。
- `/boot`，包括 Unraid 系统启动 U 盘。
- USB 后备存储、带可移动标志的设备、符号链接、嵌套挂载点，以及无法安全验证
  底层拓扑的存储。
- 任何跨文件系统的“复制后删除”操作。

Linux 无法在所有情况下判断磁盘机箱的物理位置。例如 eSATA 硬盘盒可能表现得与
内置 SATA 盘完全相同。插件会综合挂载归属、传输类型、可移动标志和 sysfs 拓扑，
无法证明安全时一律拒绝，但软件不能百分之百证明磁盘物理上位于机箱内部。

## 环境要求

| 组件 | 要求 |
|---|---|
| Unraid OS | 7.3.2 或更高版本 |
| Dynamix File Manager | 已安装 |
| PHP | Unraid 自带的 PHP 8.x，并包含 PDO SQLite |
| ZFS 支持 | Unraid 自带的 `zfs`、`zpool` 和 `lsblk` 工具 |

## 安装

### Unraid Community Applications

项目通过 Community Applications 审核后，打开 **Apps**，搜索
`Dynamix File Recycle Bin`。

### Unraid Plugin Manager

打开 **Plugins -> Install Plugin**，粘贴：

```text
https://raw.githubusercontent.com/xO-ox-ai/dynamix.file.recycle/main/dynamix.file.recycle.plg
```

插件管理器会下载带版本号的安装包、验证 SHA-256，然后执行安装钩子。安装完成后，
请打开 **设置 -> 用户程序 -> 文件回收站** 检查总开关和维护策略；打开
**工具 -> Disk Utilities -> 回收站** 可查看、还原或永久清理回收项目。

命令行安装使用同一个官方插件管理器：

```bash
/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin install \
  https://raw.githubusercontent.com/xO-ox-ai/dynamix.file.recycle/main/dynamix.file.recycle.plg
```

## 基本使用

1. 在 DFM 中打开物理阵列盘或受支持的 ZFS dataset。
2. 使用 DFM 复选框选择一个或多个文件、目录。
3. 点击原生**删除**按钮右侧的**移入回收站**。
4. 后端检查每个选中路径、挂载点、文件系统和底层设备。
5. 只有全部检查成功后才确认操作。
6. 打开 **工具 -> Disk Utilities -> 回收站**，还原或永久清理已记录项目。

设置页会列出当前通过后端安全检查的全部卷，首次安装时默认全部勾选。第一次保存后，
配置会变为明确白名单：未勾选卷的历史仍然可见，但该卷上的新回收、还原、永久清理
和自动维护都会被阻止。
阵列磁盘和 ZFS dataset 会分成两棵层级树显示，ZFS 条目保留原生的
“存储池/dataset/子 dataset”层级。

目录示例：

| 原路径 | 回收后的路径 | 数据库 |
|---|---|---|
| `/mnt/disk1/Movies/x.mkv` | `/mnt/disk1/.RecycleBin/Movies/x.mkv.__recycle_UUID` | `/mnt/disk1/.RecycleBin/.dynamix-file-recycle.sqlite` |
| `/mnt/tank/photos/a.jpg` | `/mnt/tank/.RecycleBin/photos/a.jpg.__recycle_UUID` | `/mnt/tank/.RecycleBin/.dynamix-file-recycle.sqlite` |

ZFS 示例中的 `/mnt/tank` 必须是 dataset 的真实挂载点，插件不会把存储池别名或
父文件系统当成目标 dataset。

## 持久化与诊断

- 回收内容和历史：`<volume>/.RecycleBin/` 及其中的 SQLite 数据库。
- 持久设置：`/boot/config/plugins/dynamix.file.recycle/`。
- 运行日志：`/usr/local/emhttp/state/plugins/dynamix.file.recycle/logs/dynamix.file.recycle.log`。
- 重启后仍保留的错误审计：
  `/boot/config/plugins/dynamix.file.recycle/logs/audit.log`。

运行日志位于内存，重启后会消失。错误会同时写入有大小限制的持久审计日志；回收、
还原和清理记录则保存在各卷自己的 SQLite 数据库中。

插件不会安装独立的每小时或每日维护任务。只有在设置中填写“定时清理”cron 表达式
后，才会通过 Unraid 官方 `update_cron` 生成本插件唯一的自动任务；留空时没有任何
cron 条目。到点后会清空已启用回收站，并在同一次任务中完成数据库和历史维护。

## 卸载

打开 **Plugins**，选择 **Dynamix File Recycle Bin**，点击 **Remove**。终端中的
等效插件管理器命令是：

```bash
/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin remove \
  dynamix.file.recycle.plg
```

卸载会移除插件代码、定时任务和临时日志，但会保留设置、持久审计日志，以及每个
`.RecycleBin` 目录和其中的 SQLite 数据库。如需彻底清除，请先人工核对这些目录，
确认不再需要恢复任何文件后再删除。

## 安全机制

- 所有 API 操作都要求已登录的 Unraid 管理员会话。
- API 只接受 POST，并使用 Unraid 原生 CSRF 校验。
- 短时有效的签名检查令牌会绑定路径、inode 和元数据，确保确认后处理的仍是刚才
  检查过的同一个项目。
- 符号链接别名、路径穿越、卷根目录和嵌套挂载点都会被拒绝。
- 文件系统操作前后都会记录回收、还原和清理状态，便于恢复被中断的操作。

## 支持

[GitHub Issues](https://github.com/xO-ox-ai/dynamix.file.recycle/issues)

## 许可证

MIT，详见 [LICENSE](LICENSE)。
