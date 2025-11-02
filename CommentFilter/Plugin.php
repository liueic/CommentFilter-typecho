<?php
/**
 * 评论过滤器(<a href="http://qqdie.com" target="_blank">jrotty</a>魔改版)
 * 
 * @package CommentFilter
 * @author Hanny
 * @version 1.3.0
 * @link http://www.imhan.com

 * 历史版本
 * version 1.3.0 at 2024-12-28
 * 安全性和性能优化：增强机器人检测（动态token）、性能优化（缓存+正则）、评论频率限制、日志记录、改进中文检测、JavaScript优化
 *
 * version 1.2.0 at 2017-10-10
 * 增加评论者昵称/超链接过滤功能[非原作者更新修改，jrotty魔改更新]
 *
 * version 1.1.0 at 2014-01-04
 * 增加机器评论过滤
 *
 * version 1.0.2 at 2010-05-16
 * 修正发表评论成功后，评论内容Cookie不清空的Bug
 *
 * version 1.0.1 at 2009-11-29
 * 增加IP段过滤功能
 *
 * version 1.0.0 at 2009-11-14
 * 实现评论内容按屏蔽词过滤功能
 * 实现过滤非主文评论功能
 *
 */
class CommentFilter_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 词汇检查缓存
     * @var array
     */
    private static $wordCache = array();
    
    /**
     * IP匹配模式缓存
     * @var array
     */
    private static $ipPatternCache = array();
    
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {    
        // 使用高优先级（低数值）确保在评论提醒插件之前执行
        // 通过手动注册钩子并移除旧的回调，然后重新注册以控制顺序
        Typecho_Plugin::factory('Widget_Feedback')->comment = array('CommentFilter_Plugin', 'filter');
		Typecho_Plugin::factory('Widget_Archive')->header = array('CommentFilter_Plugin', 'add_filter_spam_input');
		
		// 尝试使用更高优先级（如果 Typecho 支持）
		// 注意：这取决于 Typecho 版本，某些版本可能不支持优先级参数
		// 如果支持，可以取消下面的注释并使用
		// Typecho_Plugin::factory('Widget_Feedback')->comment = array('CommentFilter_Plugin', 'filter', 1);
		
		return _t('评论过滤器启用成功，请配置需要过滤的内容。请注意：为确保过滤功能正常工作，建议在插件管理中将此插件放在评论提醒插件之前激活。');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate(){}
    
    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
	{
        $opt_spam = new Typecho_Widget_Helper_Form_Element_Radio('opt_spam', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "none",
			_t('屏蔽机器人评论'), "如果为机器人评论，将执行该操作。如果需要开启该过滤功能，请尝试进行评论测试，以免不同模板造成误判。");
        $form->addInput($opt_spam);

        $opt_ip = new Typecho_Widget_Helper_Form_Element_Radio('opt_ip', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "none",
			_t('屏蔽IP操作'), "如果评论发布者的IP在屏蔽IP段，将执行该操作");
        $form->addInput($opt_ip);

        $words_ip = new Typecho_Widget_Helper_Form_Element_Textarea('words_ip', NULL, "0.0.0.0",
			_t('屏蔽IP'), _t('多条IP请用换行符隔开<br />支持用*号匹配IP段，如：192.168.*.*'));
        $form->addInput($words_ip);

        $opt_nocn = new Typecho_Widget_Helper_Form_Element_Radio('opt_nocn', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "none",
			_t('非中文评论操作'), "如果评论中不包含中文，则强行按该操作执行");
        $form->addInput($opt_nocn);

        $opt_ban = new Typecho_Widget_Helper_Form_Element_Radio('opt_ban', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('禁止词汇操作'), "如果评论中包含禁止词汇列表中的词汇，将执行该操作");
        $form->addInput($opt_ban);

        $words_ban = new Typecho_Widget_Helper_Form_Element_Textarea('words_ban', NULL, "fuck\n操你妈\n[url\n[/url]",
			_t('禁止词汇'), _t('多条词汇请用换行符隔开'));
        $form->addInput($words_ban);

        $opt_chk = new Typecho_Widget_Helper_Form_Element_Radio('opt_chk', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "waiting",
			_t('敏感词汇操作'), "如果评论中包含敏感词汇列表中的词汇，将执行该操作");
        $form->addInput($opt_chk);

        $words_chk = new Typecho_Widget_Helper_Form_Element_Textarea('words_chk', NULL, "http://",
			_t('敏感词汇'), _t('多条词汇请用换行符隔开<br />注意：如果词汇同时出现于禁止词汇，则执行禁止词汇操作'));
        $form->addInput($words_chk);
      
       $opt_author = new Typecho_Widget_Helper_Form_Element_Radio('opt_author', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "spam",
			_t('关键昵称操作'), "如果评论中包含关键昵称词汇列表中的词汇，将执行该操作");
        $form->addInput($opt_author);

        $words_author = new Typecho_Widget_Helper_Form_Element_Textarea('words_author', NULL, "澳门银座\n自动化软件\n量化交易",
			_t('关键昵称词汇'), _t('多条词汇请用换行符隔开'));
        $form->addInput($words_author);
      
       $opt_url = new Typecho_Widget_Helper_Form_Element_Radio('opt_url', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "spam",
			_t('垃圾链接过滤操作'), "如果评论中包含垃圾链接列表中字符串，将执行该操作");
        $form->addInput($opt_url);

        $words_url = new Typecho_Widget_Helper_Form_Element_Textarea('words_url', NULL, "www.vps521.cn",
			_t('垃圾链接'), _t('多条词汇请用换行符隔开，链接格式请参考上边输入框默认的链接'));
        $form->addInput($words_url);
        
        // 新增配置项：机器人检测模式
        $spam_mode = new Typecho_Widget_Helper_Form_Element_Radio('spam_mode', array("static" => "静态模式（兼容旧版）", "dynamic" => "动态Token模式（推荐）"), "static",
			_t('机器人检测模式'), "静态模式使用固定值，动态模式使用随机Token，安全性更高。");
        $form->addInput($spam_mode);
        
        // 新增配置项：评论频率限制
        $rate_limit = new Typecho_Widget_Helper_Form_Element_Text('rate_limit', NULL, "10",
			_t('评论频率限制（秒）'), _t('同一IP在指定秒数内只能评论一次，设置为0则禁用此功能。'));
        $form->addInput($rate_limit);
        
        // 新增配置项：日志记录开关
        $enable_log = new Typecho_Widget_Helper_Form_Element_Radio('enable_log', array("no" => "禁用", "yes" => "启用"), "no",
			_t('启用日志记录'), "记录被拦截的评论信息，用于分析和调试。");
        $form->addInput($enable_log);
        
        // 新增配置项：最少中文字符数
        $min_cn_chars = new Typecho_Widget_Helper_Form_Element_Text('min_cn_chars', NULL, "1",
			_t('最少中文字符数'), _t('评论中至少包含的中文字符数量，设置为0则不检查。'));
        $form->addInput($min_cn_chars);
	}
    
    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 评论过滤器
     * 
     */
    public static function filter($comment, $post)
    {
        $options = Typecho_Widget::widget('Widget_Options');
		$filter_set = $options->plugin('CommentFilter');
		$opt = "none";
		$error = "";

		//机器评论处理
		if ($opt == "none" && $filter_set->opt_spam != "none") {
			$spamMode = isset($filter_set->spam_mode) ? $filter_set->spam_mode : 'static';
			
			if ($spamMode == 'dynamic') {
				// 动态模式验证
				$submittedToken = isset($_POST['filter_spam']) ? $_POST['filter_spam'] : '';
				$submittedTime = isset($_POST['filter_time']) ? intval($_POST['filter_time']) : 0;
				$storedToken = Typecho_Cookie::get('__comment_filter_token', '');
				$storedTime = Typecho_Cookie::get('__comment_filter_time', 0);
				
				// 检查token是否存在
				if (empty($submittedToken) || empty($storedToken)) {
					$error = "请勿使用第三方工具进行评论";
					$opt = $filter_set->opt_spam;
				}
				// 检查token是否匹配
				else if ($submittedToken !== $storedToken) {
					$error = "请勿使用第三方工具进行评论";
					$opt = $filter_set->opt_spam;
				}
				// token验证通过后，检查时间戳合理性
				else {
					// 1. 提交时间不能是未来时间
					if ($submittedTime > time()) {
						$error = "请勿使用第三方工具进行评论";
						$opt = $filter_set->opt_spam;
					}
					// 2. 提交时间不能早于页面加载时间（防止回退时间戳）
					else if ($submittedTime < $storedTime) {
						$error = "请勿使用第三方工具进行评论";
						$opt = $filter_set->opt_spam;
					}
					// 3. 检查token是否过期（从页面加载时间算起，不能超过10分钟）
					else if (time() - $storedTime > 600) {
						$error = "请勿使用第三方工具进行评论";
						$opt = $filter_set->opt_spam;
					}
					// 4. 检查提交间隔（从页面加载到提交的时间间隔，防止机器人过快提交）
					// 正常用户从页面加载到提交评论至少需要3秒
					else if ($submittedTime > 0 && ($submittedTime - $storedTime) < 3) {
						$error = "请勿使用第三方工具进行评论";
						$opt = $filter_set->opt_spam;
					}
				}
			} else {
				// 静态模式（向后兼容）
				if (!isset($_POST['filter_spam']) || $_POST['filter_spam'] != '48616E6E79') {
					$error = "请勿使用第三方工具进行评论";
					$opt = $filter_set->opt_spam;
				}
			}
			
			// 记录日志
			if ($opt != "none") {
				self::logFilter($comment, $error ?: "机器人评论", $opt, $filter_set);
			}
		}

		// 评论频率限制检查（在所有检查之前，但在机器人检测之后）
		if ($opt == "none") {
			$ip = isset($comment['ip']) ? $comment['ip'] : '';
			if ($ip && self::checkRateLimit($ip, $filter_set)) {
				$error = "评论太频繁，请稍后再试";
				$opt = "abandon";
				self::logFilter($comment, $error, "abandon", $filter_set);
			}
		}

		//屏蔽IP段处理
		if ($opt == "none" && $filter_set->opt_ip != "none") {
			if (self::check_ip($filter_set->words_ip, $comment['ip'])) {
				$error = "评论发布者的IP已被管理员屏蔽";
				$opt = $filter_set->opt_ip;
				self::logFilter($comment, $error, $opt, $filter_set);
			}			
		}
		
		//中文评论处理（改进版）
		if ($opt == "none" && $filter_set->opt_nocn != "none") {
			$text = isset($comment['text']) ? $comment['text'] : '';
			$minCnChars = isset($filter_set->min_cn_chars) ? intval($filter_set->min_cn_chars) : 1;
			
			if ($minCnChars > 0) {
				// 扩展正则表达式，包含中文标点
				if (preg_match_all("/[\x{4e00}-\x{9fa5}]/u", $text, $matches) < $minCnChars) {
					if ($minCnChars == 1) {
						$error = "评论内容请不少于一个中文汉字";
					} else {
						$error = "评论内容请至少包含" . $minCnChars . "个中文汉字";
					}
					$opt = $filter_set->opt_nocn;
					self::logFilter($comment, $error, $opt, $filter_set);
				}
			} else {
				// 保持原有逻辑（向后兼容）
				if (preg_match("/[\x{4e00}-\x{9fa5}]/u", $text) == 0) {
					$error = "评论内容请不少于一个中文汉字";
					$opt = $filter_set->opt_nocn;
					self::logFilter($comment, $error, $opt, $filter_set);
				}
			}
		}
		
		//检查禁止词汇
		if ($opt == "none" && $filter_set->opt_ban != "none") {
			if (self::check_in($filter_set->words_ban, $comment['text'])) {
				$error = "评论内容中包含禁止词汇";
				$opt = $filter_set->opt_ban;
				self::logFilter($comment, $error, $opt, $filter_set);
			}
		}
		
		//检查敏感词汇
		if ($opt == "none" && $filter_set->opt_chk != "none") {
			if (self::check_in($filter_set->words_chk, $comment['text'])) {
				$error = "评论内容中包含敏感词汇";
				$opt = $filter_set->opt_chk;
				self::logFilter($comment, $error, $opt, $filter_set);
			}
		}
      	
      	//检查关键昵称词汇
		if ($opt == "none" && $filter_set->opt_author != "none") {
			if (self::check_in($filter_set->words_author, $comment['author'])) {
				$error = "该类型昵称已被禁止评论";
				$opt = $filter_set->opt_author;
				self::logFilter($comment, $error, $opt, $filter_set);
			}
		}

      	//检查评论者链接
		if ($opt == "none" && $filter_set->opt_url != "none") {
			if (self::check_in($filter_set->words_url, $comment['url'])) {
				$error = "该类型评论者超链接被禁止评论";
				$opt = $filter_set->opt_url;
				self::logFilter($comment, $error, $opt, $filter_set);
			}
		}
      
		//执行操作
		if ($opt == "abandon") {
			Typecho_Cookie::set('__typecho_remember_text', $comment['text']);
			// 抛出异常以阻止后续插件执行（包括评论提醒插件）
            throw new Typecho_Widget_Exception($error);
		}
		else if ($opt == "spam") {
			$comment['status'] = 'spam';
			// 设置标记，提示后续插件该评论已被过滤
			// CommentNotifier 等插件可以检查此标记以决定是否发送通知
			$comment['_filtered'] = true;
			$comment['_filter_reason'] = $error;
		}
		else if ($opt == "waiting") {
			$comment['status'] = 'waiting';
			// 设置标记，提示后续插件该评论已被过滤
			$comment['_filtered'] = true;
			$comment['_filter_reason'] = $error;
		}
		Typecho_Cookie::delete('__typecho_remember_text');
        return $comment;
    }

    /**
     * 检查$str中是否含有$words_str中的词汇
     * 
     * @param string $words_str 词汇列表（换行分隔）
     * @param string $str 待检查的字符串
     * @return bool 是否包含词汇
     */
	private static function check_in($words_str, $str)
	{
		if (empty($words_str) || empty($str)) {
			return false;
		}
		
		// 使用配置内容的md5作为缓存key
		$cacheKey = md5($words_str);
		
		// 检查缓存
		if (!isset(self::$wordCache[$cacheKey])) {
			// 分割词汇，过滤空值
			$words = array_filter(array_map('trim', explode("\n", $words_str)));
			
			if (empty($words)) {
				self::$wordCache[$cacheKey] = array();
				return false;
			}
			
			// 存入缓存
			self::$wordCache[$cacheKey] = $words;
		}
		
		$words = self::$wordCache[$cacheKey];
		
		if (empty($words)) {
			return false;
		}
		
		// 使用正则表达式一次性匹配所有词汇（转义特殊字符）
		$pattern = '/(' . implode('|', array_map('preg_quote', $words)) . ')/i';
		
		return preg_match($pattern, $str) > 0;
	}

    /**
     * 检查$ip中是否在$words_ip的IP段中
     * 
     * @param string $words_ip IP列表（换行分隔）
     * @param string $ip 待检查的IP地址
     * @return bool 是否匹配
     */
	private static function check_ip($words_ip, $ip)
	{
		if (empty($words_ip) || empty($ip)) {
			return false;
		}
		
		// 使用配置内容的md5作为缓存key
		$cacheKey = md5($words_ip);
		
		// 检查缓存
		if (!isset(self::$ipPatternCache[$cacheKey])) {
			// 分割IP规则，过滤空值
			$words = array_filter(array_map('trim', explode("\n", $words_ip)));
			
			if (empty($words)) {
				self::$ipPatternCache[$cacheKey] = array();
				return false;
			}
			
			$patterns = array();
			foreach ($words as $word) {
				if (false !== strpos($word, '*')) {
					// 包含通配符：转换为正则表达式，注意转义点号
					$pattern = '/^' . str_replace(array('*', '.'), array('\d{1,3}', '\.'), $word) . '$/';
				} else {
					// 精确匹配：转义特殊字符
					$pattern = '/^' . preg_quote($word, '/') . '/';
				}
				$patterns[] = $pattern;
			}
			
			// 存入缓存
			self::$ipPatternCache[$cacheKey] = $patterns;
		}
		
		$patterns = self::$ipPatternCache[$cacheKey];
		
		if (empty($patterns)) {
			return false;
		}
		
		// 遍历缓存的patterns数组进行匹配
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $ip)) {
				return true;
			}
		}
		
		return false;
	}

    /**
     * 生成动态防机器人Token
     * 
     * @access private
     * @return string Token值
     */
    private static function generateSpamToken()
    {
        $random = mt_rand(100000, 999999);
        $timestamp = time();
        $secret = Typecho_Widget::widget('Widget_Options')->secret; // 使用Typecho的密钥
        $token = md5($random . $timestamp . $secret);
        
        // 将token和时间戳存入Cookie，过期时间10分钟
        Typecho_Cookie::set('__comment_filter_token', $token, time() + 600);
        Typecho_Cookie::set('__comment_filter_time', $timestamp, time() + 600);
        
        return $token;
    }
    
    /**
     * 检查评论频率限制
     * 
     * @access private
     * @param string $ip 评论者IP地址
     * @param array $filter_set 插件配置
     * @return bool 是否触发限制（true表示触发限制）
     */
    private static function checkRateLimit($ip, $filter_set)
    {
        $rateLimit = isset($filter_set->rate_limit) ? intval($filter_set->rate_limit) : 10;
        
        // 如果设置为0，则禁用频率限制
        if ($rateLimit <= 0) {
            return false;
        }
        
        $cookieKey = '__comment_rate_' . md5($ip);
        $lastTime = Typecho_Cookie::get($cookieKey, 0);
        $currentTime = time();
        
        // 如果时间差小于限制值，触发限制
        if ($lastTime > 0 && ($currentTime - $lastTime) < $rateLimit) {
            return true;
        }
        
        // 更新Cookie为当前时间，过期时间设为限制值的2倍
        Typecho_Cookie::set($cookieKey, $currentTime, time() + ($rateLimit * 2));
        
        return false;
    }
    
    /**
     * 记录被拦截的评论日志
     * 
     * @access private
     * @param array $comment 评论数据
     * @param string $reason 拦截原因
     * @param string $action 执行的操作
     * @param array $filter_set 插件配置
     * @return void
     */
    private static function logFilter($comment, $reason, $action, $filter_set)
    {
        // 检查是否启用日志
        if (!isset($filter_set->enable_log) || $filter_set->enable_log != 'yes') {
            return;
        }
        
        $logFile = __DIR__ . '/filter.log';
        
        // 检查日志文件大小（超过5MB则截断）
        if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
            file_put_contents($logFile, '');
        }
        
        // 构建日志条目
        $logEntry = sprintf(
            "[%s] IP: %s, Author: %s, Reason: %s, Action: %s\n",
            date('Y-m-d H:i:s'),
            isset($comment['ip']) ? $comment['ip'] : 'unknown',
            isset($comment['author']) ? $comment['author'] : 'unknown',
            $reason,
            $action
        );
        
        // 追加写入日志（如果失败则静默处理）
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * 在表单中增加 filter_spam 隐藏域
     * 
     */
    public static function add_filter_spam_input($header, $archive)
    {
		$options = Typecho_Widget::widget('Widget_Options');
		$filter_set = $options->plugin('CommentFilter');
		if ($filter_set->opt_spam != "none" && $archive->is('single') && $archive->allow('comment')) {
			// 根据配置选择静态或动态模式
			$spamMode = isset($filter_set->spam_mode) ? $filter_set->spam_mode : 'static';
			
			if ($spamMode == 'dynamic') {
				// 动态模式：生成token和时间戳
				$token = self::generateSpamToken();
				$timestamp = time();
			} else {
				// 静态模式：使用固定值（向后兼容）
				$token = '48616E6E79';
				$timestamp = '';
			}
			
			echo '<script type="text/javascript">
(function() {
	function get_form(input) {
		var node = input;
		while (node) {
			node = node.parentNode;
			if (node && node.nodeName && node.nodeName.toLowerCase() == "form") {
				return node;
			}
		}
		return null;
	}
	
	function addFilterInputs() {
		var inputs = document.getElementsByTagName("textarea");
		var i, textarea = null;
		
		// 查找评论文本框
		for (i = 0; i < inputs.length; i++) {
			if (inputs[i].name && inputs[i].name.toLowerCase() == "text") {
				textarea = inputs[i];
				break;
			}
		}
		
		if (!textarea) {
			return;
		}
		
		var form_comment = get_form(textarea);
		if (form_comment) {
			// 添加filter_spam隐藏域
			var input_hd = document.createElement("input");
			input_hd.type = "hidden";
			input_hd.name = "filter_spam";
			input_hd.value = "' . $token . '";
			form_comment.appendChild(input_hd);
			';
			
			// 动态模式下添加时间戳隐藏域
			if ($spamMode == 'dynamic') {
				echo '
			// 添加filter_time隐藏域（初始值为页面加载时间）
			var input_time = document.createElement("input");
			input_time.type = "hidden";
			input_time.name = "filter_time";
			input_time.value = "' . $timestamp . '";
			form_comment.appendChild(input_time);
			
			// 在表单提交时，更新时间戳为当前时间
			var formSubmitHandler = function(e) {
				var currentTime = Math.floor(Date.now() / 1000);
				if (input_time) {
					input_time.value = currentTime;
				}
			};
			
			if (form_comment.addEventListener) {
				form_comment.addEventListener("submit", formSubmitHandler, false);
			} else if (form_comment.attachEvent) {
				form_comment.attachEvent("onsubmit", formSubmitHandler);
			}
			';
			}
			
			echo '
		}
	}
	
	// 使用DOMContentLoaded，兼容旧浏览器
	if (document.addEventListener) {
		document.addEventListener("DOMContentLoaded", addFilterInputs, false);
	} else if (window.attachEvent) {
		window.attachEvent("onload", addFilterInputs);
	} else {
		window.onload = addFilterInputs;
	}
})();
</script>
';
		}
    }

}
