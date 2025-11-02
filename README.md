# CommentFilter-typecho
> CommentFilter反垃圾评论插件 (jrotty魔改版)

## 安装方法
将CommentFilter文件夹传到typecho插件目录即可完成插件的安装

## 重要提示：插件兼容性

### 与其他插件的执行顺序

如果您的站点同时使用了**评论提醒插件**（如 CommentNotifier）等其他评论相关插件，**请确保 CommentFilter 插件在评论提醒插件之前激活**，这样可以确保过滤功能在发送通知之前执行。

### 如何调整插件执行顺序

1. 进入 Typecho 后台 → **插件管理**
2. **先禁用 CommentNotifier**（评论提醒插件）
3. **再禁用 CommentFilter**（评论过滤器）
4. **先激活 CommentFilter**（评论过滤器）
5. **再激活 CommentNotifier**（评论提醒插件）

这样确保 CommentFilter 的钩子在 CommentNotifier 之前注册和执行。

### 插件协作机制

CommentFilter 会在被拦截的评论对象上设置 `_filtered` 标记：
- 如果评论被标记为 `abandon`（拒绝），会直接抛出异常，阻止后续插件执行
- 如果评论被标记为 `spam` 或 `waiting`，会设置 `_filtered = true` 标记
- 其他插件（如评论提醒插件）可以检查此标记来决定是否处理该评论

**注意**：如果评论提醒插件未实现 `_filtered` 标记检查，可能仍会发送通知。建议检查评论提醒插件是否支持该机制，或联系其开发者添加支持。

## 版本历史

- **v1.3.0** (2024-12-28): 安全性和性能优化
  - 增强机器人检测（动态token）
  - 性能优化（缓存+正则表达式）
  - 评论频率限制
  - 日志记录功能
  - 改进中文检测
  - JavaScript优化

- **v1.2.0**: 增加评论者昵称/超链接过滤功能
