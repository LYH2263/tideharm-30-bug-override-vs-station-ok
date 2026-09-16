# 09-tideharm（潮谐）

港口潮位调和预报台。用分潮振幅与迟角合成潮位过程线，对照实测点看残差。

## 启动

```bash
docker compose up --build
```

| 入口 | 地址 |
| --- | --- |
| 前端 | http://localhost:3800 |
| API | http://localhost:8800 |

## 主链

选港口站 → 维护分潮 → 合成预报曲线 → 对照实测看残差表。

## 阈值与审计

残差阈值支持全局默认（设置页）与站级覆盖（站详情页），站级覆盖优先于全局默认。
写入站覆盖、清空覆盖、修改全局默认都会追加一条审计（对象、旧值、新值、来源切换、操作时刻），
审计页支持按站过滤，`?station=global` 可单看全局变更。

| 接口 | 说明 |
| --- | --- |
| `PUT /api/stations/{slug}/threshold` | 写入站级覆盖 `{threshold_m}` |
| `DELETE /api/stations/{slug}/threshold` | 清空站级覆盖（无覆盖时幂等，不记审计） |
| `GET /api/threshold-audits?station=&limit=` | 审计列表，`station` 为站 slug 或 `global`，缺省返回全部 |

## 技术栈

PHP 8 + SQLite；Vue 3 + Vite。
