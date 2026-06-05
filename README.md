# Hook 类文档

## 概述

`Hook` 类实现了一个灵活的钩子系统（Hook System），允许在应用程序中注册、管理和执行钩子函数。钩子函数可以设置优先级、控制执行流程、传递上下文。该类采用静态方法设计，**禁止实例化**。

---

## 常量

### 钩子标志位（Hook Flags）
这些常量用于定义钩子函数的执行行为，通过按位或（`|`）组合到优先级参数中。

| 常量 | 值 | 描述 |
|------|----|------|
| `HOOK_FLAG_LEVEL_MASK` | `0xfffff` | 优先级掩码，提取低20位作为优先级数值（0-1,048,575）。 |
| `HOOK_FLAG_WAIT_FUNC` | `0x100000` | 执行钩子时忽略用户中止（`ignore_user_abort(true)`），确保函数执行完毕。 |
| `HOOK_FLAG_CLEAN_RETURN` | `0x200000` | 钩子执行后，将返回的上下文清空为 `null`。 |
| `HOOK_FLAG_IGNORE_RETURN` | `0x400000` | 忽略钩子返回值，不更新上下文。 |
| `HOOK_FLAG_NULL_CONTENT` | `0x800000` | 向钩子回调传递 `null` 作为上下文，而不是上一个钩子的返回值。 |
| `HOOK_FLAG_LAST_STAGE` | `0x1000000` | 当前钩子执行后，终止后续所有钩子的执行（类似 `break`）。 |

### 控制标志（Control Flags）
这些常量用于在钩子执行期间控制流程，通过 `control()` 方法设置。

| 常量 | 值 | 描述 |
|------|----|------|
| `CTL_NONE` | `0` | 无特殊控制，正常执行。 |
| `CTL_STOP` | `1` | 立即停止整个钩子链的执行。 |
| `CTL_JUMP` | `2` | 跳过当前钩子（不执行），继续下一个钩子。 |

---

## 静态属性

| 属性 | 类型 | 描述 |
|------|------|------|
| `$fctl` | `int` | 当前控制标志，由 `control()` 方法管理。 |
| `$hook_map_result` | `ArrayObject` | 存储所有注册钩子的映射，结构为：<br>`['hook_name' => ['is_sorted' => bool, 'call' => [[flag, callback, id], ...]]]` |
| `$is_load_hook_file` | `ArrayObject` | 记录已加载的钩子定义文件路径，避免重复加载。 |

---

## 方法

### `__construct()`
```php
public function __construct()
```
**作用**：禁止实例化。构造函数抛出 `Exception('Dont Instance this object')`。

---

### `init()`
```php
public static function init(): void
```
**作用**：初始化静态属性，创建 `ArrayObject` 实例。应在应用程序启动时调用一次。

---

### `set(string $hook_name, int $level, callable $callback): void`
**作用**：注册一个钩子函数。

| 参数 | 类型 | 描述 |
|------|------|------|
| `$hook_name` | `string` | 钩子名称（标识符） |
| `$level` | `int` | 优先级和标志位的组合。低20位为优先级（数值越小越先执行），高位可附加 `HOOK_FLAG_*` 常量。 |
| `$callback` | `callable` | 钩子回调函数。签名应为：`function(object $WebObject, mixed $context): mixed`。 |

**内部行为**：
- 为每个钩子分配一个自增 ID，用于稳定排序。
- 如果钩子已存在，追加到 `call` 数组，并重置 `is_sorted` 为 `false`（表示需要重新排序）。
- 否则，创建新的条目。

**示例**：
```php
Hook::set('user.login', 10, function($app, $ctx) {
    return $ctx;
});

Hook::set('user.login', Hook::HOOK_FLAG_WAIT_FUNC | 50, function($app, $ctx) {
    return $ctx;
});
```

---

### `unset(string $hook_name): bool`
**作用**：移除指定名称的钩子。

| 参数 | 类型 | 描述 |
|------|------|------|
| `$hook_name` | `string` | 钩子名称 |

**返回**：成功移除返回 `true`，钩子不存在返回 `false`。

---

### `control(int|null $ctl = null): int`
**作用**：获取或设置控制标志。

| 参数 | 类型 | 描述 |
|------|------|------|
| `$ctl` | `int|null` | 如果提供，则设置当前控制标志；否则仅返回当前标志。 |

**返回**：当前控制标志（整数）。

**示例**：
```php
Hook::control(Hook::CTL_STOP); // 设置停止标志
$flag = Hook::control();        // 获取当前标志
```

---

### `load(string $hookfile): bool`
**作用**：加载一个钩子定义文件。文件内应包含 `Hook::set()` 等注册代码。

| 参数 | 类型 | 描述 |
|------|------|------|
| `$hookfile` | `string` | 钩子定义文件的路径（绝对或相对）。 |

**返回**：加载成功返回 `true`，文件不存在或已加载过返回 `false`（已加载过会提前返回 `true`，不重复加载）。

**示例**：
```php
Hook::load('/path/to/hooks/user_hooks.php');
```

---

### `run(string $hook_name, object $WebObject): mixed`
**作用**：执行指定钩子链中的所有钩子，并按顺序传递上下文。

| 参数 | 类型 | 描述 |
|------|------|------|
| `$hook_name` | `string` | 要执行的钩子名称 |
| `$WebObject` | `object` | 传递给钩子回调的第一个参数（通常为应用对象或请求对象）。 |

**返回**：最终上下文的返回值（经过所有钩子处理），如果钩子不存在或没有返回值，返回 `null`。

**执行流程**：
1. 如果钩子不存在，直接返回 `null`。
2. 调用 `readyhook()` 确保钩子已排序（`usort` 在 PHP 8+ 中总是返回 `true`，因此 `is_sorted` 始终被设为 `true`）。
3. 初始化控制标志为 `CTL_NONE`。
4. 依次遍历每个钩子：
   - 如果当前控制标志为 `CTL_JUMP`，则清除该标志并跳过当前钩子（不执行）。
   - 否则，根据钩子标志处理：
     - `HOOK_FLAG_WAIT_FUNC`：临时设置 `ignore_user_abort(true)`。
     - `HOOK_FLAG_IGNORE_RETURN`：调用回调但不更新上下文。
     - `HOOK_FLAG_NULL_CONTEXT`：向回调传递 `null` 作为上下文。
     - 其他情况：调用回调并更新上下文。
   - 若钩子设置了 `HOOK_FLAG_CLEAN_RETURN`，则清空上下文为 `null`。
   - 若钩子设置了 `HOOK_FLAG_LAST_STAGE` 或控制标志为 `CTL_STOP`，则停止循环。
5. 返回最终的上下文。

**注意**：异常会被捕获并输出错误消息，但不会中断钩子链。

---

### `check(string $hook_name): bool`
**作用**：检查指定名称的钩子是否已注册。

| 参数 | 类型 | 描述 |
|------|------|------|
| `$hook_name` | `string` | 钩子名称 |

**返回**：已注册返回 `true`，否则 `false`。

---

### `map(): array`
**作用**：返回所有已注册的钩子名称列表。

**返回**：字符串数组。

---

### `start(string $hook_file, string $hook_name, object $app): array`
**作用**：便捷方法，加载钩子文件并执行完整的钩子流程（前置钩子 → 主钩子 → 后置钩子）。

| 参数 | 类型 | 描述 |
|------|------|------|
| `$hook_file` | `string` | 钩子定义文件路径 |
| `$hook_name` | `string` | 要执行的钩子名称 |
| `$app` | `object` | 传递给钩子回调的应用对象 |

**返回**：关联数组，包含两个键：
- `status`：`bool`，执行是否成功（钩子文件加载且主钩子存在时为 `true`）。
- `response`：`mixed`，主钩子的返回值（若未执行或失败则为 `null`）。

**执行顺序**：
1. 调用 `load($hook_file)` 加载钩子文件。
2. 如果加载失败或主钩子 `$hook_name` 不存在，返回 `['status' => false, 'response' => null]`。
3. 设置 `status` 为 `true`。
4. 如果存在前置钩子 `{$hook_name}.before`，则执行它，并检查其返回值：
   - 若 `run($before, $app)` 返回 **`true`**，则允许继续执行主钩子（变量 `$next` 为 `true`）。
   - 否则（返回值不为 `true`）跳过主钩子。
5. 如果允许执行主钩子（`$next === true`），则执行主钩子 `$hook_name`，并将返回值存入 `response`。
6. 如果存在后置钩子 `{$hook_name}.after`，则执行它（无论主钩子是否执行，也不影响返回值）。
7. 返回结果数组。

**重要说明**：
- 前置钩子通过返回值控制主钩子是否执行，只有返回 **`true`** 时才会继续。
- 后置钩子总是在最后执行，不依赖前置或主钩子的结果。
- 前置、后置钩子与主钩子之间**不共享上下文**，它们各自独立运行，接收的第二个参数（上下文）始终为 `null`（因为 `run` 传递的 `$app` 是对象，但上下文初始为 `null`，且没有链式传递）。
- 如需在主钩子前传递数据，应直接在主钩子链中通过优先级控制顺序，而不是依赖 `.before` 钩子。

---

## 内部方法

### `readyhook(string $hook_name): bool`
**作用**：确保指定钩子的回调数组已按优先级和 ID 排序。

- 如果 `is_sorted` 为 `false`，则调用 `usort` 对 `call` 数组排序，并将 `is_sorted` 设为 `usort` 的返回值（PHP 8+ 中始终为 `true`）。
- 返回 `true`。

**排序规则**：
1. 首先按优先级（`$flag & HOOK_FLAG_LEVEL_MASK`）升序。
2. 优先级相同时，按注册 ID（`$id`）升序，保证稳定排序。

---

## 使用示例

### 1. 初始化与注册钩子
```php
Hook::init();

// 普通钩子
Hook::set('api.before', 10, function($app, $ctx) {
    echo "Before API call\n";
    return $ctx;
});

// 带标志的钩子
Hook::set('api.after', Hook::HOOK_FLAG_IGNORE_RETURN | Hook::HOOK_FLAG_CLEAN_RETURN | 20, function($app, $ctx) {
    echo "After API call\n";
    return "some data";
});
```

### 2. 加载钩子文件
```php
// hooks/system.php
Hook::set('system.init', 5, function($app, $ctx) {
    // 初始化系统
    return $ctx;
});

Hook::load('/path/to/hooks/system.php');
```

### 3. 执行钩子链
```php
$app = new stdClass(); // 应用对象
$result = Hook::run('system.init', $app);
echo $result; // 输出钩子返回的内容
```

### 4. 使用 start 方法（含前置/后置钩子）
```php
$app = new App();
$result = Hook::start('/path/to/hooks/user.php', 'user.login', $app);
if ($result['status']) {
    echo "登录成功，响应：", $result['response'];
} else {
    echo "登录失败";
}

// 定义钩子
Hook::set('user.login', 10, function($app, $ctx) {
    return $app->login(); // 返回登录结果
});

// 前置钩子：权限检查（返回 true 才允许登录）
Hook::set('user.login.before', 1, function($app, $ctx) {
    return $app->hasPermission(); // 必须返回 true
});

// 后置钩子：记录日志（返回值不影响主流程）
Hook::set('user.login.after', 1, function($app, $ctx) {
    error_log("User login attempted");
});
```

### 5. 控制流程
```php
// 在钩子内部动态停止执行
Hook::set('auth.check', 1, function($app, $ctx) {
    if (!$app->isAuthenticated()) {
        Hook::control(Hook::CTL_STOP); // 停止后续钩子
        return false;
    }
    return $ctx;
});
```

---

## 注意事项

1. **PHP 版本要求**：类中使用了 PHP 8.0+ 的类型声明。
2. **禁止实例化**：构造函数抛出异常，所有方法均为静态调用。
3. **优先级**：优先级值（低20位）越小，钩子越先执行。相同优先级按注册顺序执行。
4. **上下文传递**：钩子链通过 `$context` 参数传递数据，每个钩子可以修改并返回新的上下文（除非使用 `HOOK_FLAG_IGNORE_RETURN`）。
5. **前置钩子控制**：`start()` 方法中的前置钩子 `.before` 必须返回 `true` 才会执行主钩子，否则跳过。
6. **文件加载**：`load()` 使用 `require_once` 语义，且通过 `$is_load_hook_file` 记录已加载文件，避免重复加载。
7. **控制标志**：`CTL_JUMP` 和 `CTL_STOP` 可在钩子回调中动态设置，影响后续钩子的执行。
8. **异常处理**：`run()` 方法会捕获钩子回调中的 `Exception`，仅输出错误消息，不中断钩子链。实际生产环境建议加强日志记录。

---

## 后记

`Hook` 类提供了一个轻量级、可扩展的钩子系统，适用于中间件、插件、事件驱动等场景。通过优先级、标志位和动态控制，可以实现灵活的执行流程。`start()` 方法进一步简化了文件加载与前后置钩子的集成，但需注意前置钩子返回 `true` 才能触发主钩子的特殊逻辑。
