# Dynamix File Recycle Bin 文件回收站

> English documentation: [README.md](README.md)

这是一个面向 Unraid 官方内置 Dynamix File Manager（DFM，文件浏览器）的受保护
回收站插件。它会在
DFM 原生**删除**按钮右侧增加一个跟随勾选状态的**移入回收站**按钮，并通过同一
文件系统内的原子重命名，把选中的文件或目录移入所属卷的 `.RecycleBin`。

插件只处理用户在官方内置文件浏览器中点击独立**移入回收站**按钮发起的操作。
DFM 原生**删除**按钮仍然是永久删除；插件不会拦截 SMB/NFS、终端、Docker 容器、
应用程序、脚本或第三方文件管理器执行的删除。

当前版本刻意只处理简单且能够验证的存储结构。确认前后端会检查每个选中项目，
任何路径不受支持都会显示明确原因和建议。

## 支持范围

- Unraid 阵列挂载点（例如 `/mnt/disk1`）中的普通文件和目录。
- 底层设备可以确认不是 USB、不是可移动介质的本地 ZFS dataset。每个 dataset
  分别拥有自己的 `.RecycleBin` 和 SQLite 数据库。
- 只允许同一文件系统内的原子重命名。

以下情况会被明确拒绝：

- `/mnt/user`、`/mnt/user0` 虚拟用户共享路径。
- `/mnt/cache*` 缓存路径。独立挂载的本地 ZFS 存储池只要底层设备通过常规安全检查，
  仍然属于支持范围。
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
| Unraid OS | 7.2.4 或更高版本 |
| Dynamix File Manager | 已安装 |
| PHP | Unraid 自带的 PHP 8.x |
| SQLite | Unraid 自带的 `/usr/bin/sqlite3` 命令行客户端 |
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
当 ZFS dataset 位于阵列盘或另一个本地存储池下方时，文件始终回收到该 dataset
自己的 `.RecycleBin`，不会回退到父磁盘或父存储池。

### 需要注意的行为

- `.RecycleBin` 按需动态创建：只有某个已启用磁盘或 ZFS dataset 的第一次回收操作
  进入数据库初始化阶段时，才会在该卷的准确根目录下创建。安装插件或勾选卷不会
  提前创建此文件夹。
- 每个子 ZFS dataset 都是独立管理边界；启用父磁盘或父存储池不会自动启用子
  dataset。
- 关闭某个卷只会阻止该卷上的回收、还原、永久删除和维护，不会删除已有回收文件
  或历史记录。
- 已还原记录的原始路径仍存在时，可以点击并跳转到 Unraid 官方内置文件浏览器的
  对应位置；路径缺失时只显示普通文字。
- 列表支持本页批量选择、服务端分页，以及按名称、时间或大小排序；历史记录不能
  再次选中执行操作。
- **不要手动移动、重命名、删除或编辑 `.RecycleBin` 里的任何内容。**其中包括回收
  文件，以及隐藏的 SQLite、`-wal` 和 `-shm` 文件。手动操作可能使文件与记录失去
  对应关系或损坏恢复历史；所有还原和永久删除都应通过插件回收站页面执行。

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

如需分析操作失败，请将**日志级别**设为 **DEBUG** 并保存，复现问题后在设置中点击
**下载诊断日志包**。临时 `.tar.gz` 包含插件日志及有限的存储、PHP、SQLite 状态
快照，但不会包含回收文件内容；下载后服务器端临时压缩包会立即删除。

运行日志位于内存，重启后会消失。错误会同时写入有大小限制的持久审计日志；回收、
还原和清理记录则保存在各卷自己的 SQLite 数据库中。

如果无视上述警告，手动删除某个 dataset 的 `.RecycleBin` 会永久丢失其中全部文件和该 dataset 的
SQLite 历史。插件没有常驻进程持有已删除的数据库；后续回收会重新创建空目录和表
结构并继续工作。若删除动作与回收/还原并发发生，该次插件请求可能失败，但异常会
限制在插件请求内。

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

因此卸载后重新安装会继续使用原来的卷白名单和其他设置，不会被当成首次安装并
重新默认勾选全部受支持卷。

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
