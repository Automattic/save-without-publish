# Config fixtures

Each file returns what `VIP_SAVE_WITHOUT_PUBLISH_CONFIG` can hold, one per state the plugin has to
survive. `tests/test-config.php` loads them through `Config::from_raw()`.

| File | State | Policy in force |
| --- | --- | --- |
| `config-valid.php` | Setting set to `always` | `always` |
| `config-minimal.php` | Setting set to its default | `never` |
| `config-incomplete.php` | Constant defined, nothing set yet (setup in progress) | `never` |
| `config-invalid.php` | Setting is not a recognised policy | `never` |

There is no fixture for a constant that is not defined at all, because a constant cannot be
un-defined. The tests cover it with `Config::from_raw( null )`.
