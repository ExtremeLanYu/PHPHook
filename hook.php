<?php
/**
 * 钩子
 */
class Hook
{
	/**
	 * 设置顺序掩码:0xfffff
	 * @var int
	 */
	public const int HOOK_FLAG_LEVEL_MASK = 0xfffff;
	/**
	 * 等待函数执行完成
	 * @var int
	 */
	public const int HOOK_FLAG_WAIT_FUNC = 0x100000;
	/**
	 * 清除返回内容
	 * @var int
	 */
	public const int HOOK_FLAG_CLEAN_RETURN = 0x200000;
	/**
	 * 忽略返回
	 * @var int
	 */
	public const int HOOK_FLAG_IGNORE_RETURN = 0x400000;
	/**
	 * 空上下文
	 * @var int
	 */
	public const int HOOK_FLAG_NULL_CONTENT = 0x800000;
	/**
	 * 此函数后停止
	 * @var int
	 */
	public const int HOOK_FLAG_LAST_STAGE = 0x1000000;
	//const HOOK_FLAG_NO_TIME_OUT=0x200000;
	public const int CTL_NONE = 0;
	public const int CTL_STOP = 1;
	public const int CTL_JUMP = 2;
	protected static int $fctl = 0;
	/**
	 * 全局钩子映射,结构: ['hook_name' => [[flag, 回调函数,id], ...],...]
	 * @var ArrayObject
	 */
	protected static ArrayObject $hook_map_result;
	/**
	 * 注册文件记录
	 * @var ArrayObject
	 */
	protected static ArrayObject $is_load_hook_file;
	public function __construct()
	{
		throw new Exception('Dont Instance this object');
	}
	protected static function readyhook($hook_name)
	{
		if (!(self::$hook_map_result[$hook_name]['is_sorted'])) {
			self::$hook_map_result[$hook_name]['is_sorted'] = usort((self::$hook_map_result[$hook_name]['call']), function (mixed $a, mixed $b) {
				$fa = $a[0] & self::HOOK_FLAG_LEVEL_MASK;
				$fb = $b[0] & self::HOOK_FLAG_LEVEL_MASK;
				if ($fa === $fb) {
					return $a[2] <=> $b[2];
				}
				return $fa > $fb ? 1 : -1;
			});
		}
		return true;
	}
	/**
	 * 初始化钩子容器
	 * @return void
	 */
	public static function init()
	{
		self::$hook_map_result = new ArrayObject();
		self::$is_load_hook_file = new ArrayObject();
	}
	/**
	 * 注册钩子函数
	 * 
	 * @param string $hook_name 钩子名称
	 * @param int $level 优先级
	 * @param callable $callback 回调函数
	 * @return void
	 */
	public static function set(string $hook_name, int $level, callable $callback): void
	{
		static $id = 0;
		if (self::$hook_map_result->offsetExists($hook_name)) {
			self::$hook_map_result[$hook_name]['call'][] = [$level, $callback, $id];
			self::$hook_map_result[$hook_name]['is_sorted'] = false;
		} else {
			self::$hook_map_result[$hook_name] = ['is_sorted' => false, 'call' => [[$level, $callback, $id]]];
		}
		++$id;
	}

	/**
	 * 取消钩子
	 * @param string $hook_name 钩子名称
	 * @return bool
	 */
	public static function unset(string $hook_name): bool
	{
		if (self::$hook_map_result->offsetExists($hook_name)) {
			self::$hook_map_result->offsetUnset($hook_name);
			return true;
		}
		return false;
	}
	/**
	 * 设置标志位
	 * @param int|null $ctl 控制标志位
	 * @return int 返回标志位
	 */
	public static function control(int|null $ctl = null): int
	{
		if ($ctl !== null) {
			self::$fctl = $ctl;
		}
		return self::$fctl;
	}
	/**
	 * 初始化钩子函数
	 * 
	 * @param string $hookfile 文件名称,例如:system
	 * @return bool 初始化是否成功
	 */
	public static function load(string $hookfile): bool
	{
		if (empty($hookfile) || !is_file($hookfile) || !is_executable($hookfile)) {
			return false;
		}
		if (self::$is_load_hook_file->offsetExists($hookfile) && self::$is_load_hook_file->offsetGet($hookfile)) {
			return true;
		}
		require_once $hookfile;
		self::$is_load_hook_file[$hookfile] = true;
		return true;
	}
	/**
	 * 执行钩子函数
	 * 
	 * @param string $hook_name 要执行的钩子名称
	 * @param object $WebObject 传递给回调函数的参数对象
	 * @return mixed 回调函数的执行结果，钩子不存在时返回null
	 */
	public static function run(string $hook_name, object $WebObject): mixed
	{
		$context = null;
		$ignore = 0;
		if (!isset(self::$hook_map_result[$hook_name])) {
			return $context;
		}
		self::readyhook($hook_name);
		self::$fctl = self::CTL_NONE;
		foreach (self::$hook_map_result[$hook_name]['call'] as $hook) {
			if (!(self::$fctl & self::CTL_JUMP)) {
				if ($hook[0] & self::HOOK_FLAG_WAIT_FUNC) {
					$ignore = ignore_user_abort(true);
				}
				try {
					if ($hook[0] & self::HOOK_FLAG_IGNORE_RETURN) {
						$hook[1]($WebObject, (self::HOOK_FLAG_NULL_CONTENT & $hook[0]) ? null : $context);
					} else {
						$context = $hook[1]($WebObject, (self::HOOK_FLAG_NULL_CONTENT & $hook[0]) ? null : $context);
					}
				} catch (\Exception $e) {
					echo $e->getMessage();
				} finally {
					if ($hook[0] & self::HOOK_FLAG_WAIT_FUNC) {
						ignore_user_abort($ignore);
					}
				}
			} else {
				self::$fctl ^= self::CTL_JUMP;
			}
			if ($hook[0] & self::HOOK_FLAG_CLEAN_RETURN) {
				$context = null;
			}
			if (($hook[0] & self::HOOK_FLAG_LAST_STAGE) || (self::$fctl & self::CTL_STOP)) {
				break;
			}
		}
		return $context;
	}
	/**
	 * 检查钩子函数是否已注册
	 * 
	 * @param string $hook_name 要检查的钩子名称
	 * @return bool 钩子是否存在
	 */
	public static function check(string $hook_name): bool
	{
		return self::$hook_map_result->offsetExists($hook_name);
	}
	/**
	 * 返回钩子注册键
	 * @return array
	 */
	public static function map(): array
	{
		return array_keys(self::$hook_map_result);
	}
	/**
	 * 加载钩子文件并启动指定钩子过程
	 * @param string $hook_file 钩子文件路径
	 * @param string $hook_name 钩子
	 * @param object $app 传递对象
	 * @return array{response: mixed, status: bool}
	 */
	public static function start(string $hook_file, string $hook_name, object $app)
	{
		$next = true;
		$response = ['status' => false, 'response' => null];
		$before = "{$hook_name}.before";
		$after = "{$hook_name}.after";
		do{
			if (!self::load($hook_file)) {break;}
			if (!self::check($hook_name)) {break;}
			$response['status'] = true;
			if (self::check($before)) {
				$next = self::run($before, $app) === false;
			}
			if ($next) {
				$response['response'] = self::run($hook_name, $app);
			}
			if (self::check($after)) {
				self::run($after, $app);
			}
		}while(false);
		return $response;
	}
}
