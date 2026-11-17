import { useState } from 'react';
import { Alert, Button, Card, Col, Form, Input, InputNumber, Row, Select, Space } from 'antd';
import type { FormInstance } from 'antd';
import { api } from './api';

const fields = [
  ['length', '车身长度（米）', 0.5, 100],
  ['width', '车身宽度（米）', 0.1, 20],
  ['capacity', '载客人数', 1, 1000],
  ['bogie1Position', '转向架 1 位置（米）', -50, 50],
  ['bogie2Position', '转向架 2 位置（米）', -50, 50],
  ['couplingPadding1', '前端连接间距（米）', 0, 10],
  ['couplingPadding2', '后端连接间距（米）', 0, 10],
] as const;

export const defaultCar = {
  vehicleId: '',
  length: 4,
  width: 2,
  capacity: 4,
  bogie1Position: -1,
  bogie2Position: 1,
  couplingPadding1: 0,
  couplingPadding2: 0,
};

type Siding = {
  id: string;
  name: string;
  depotId: string | null;
  accelerationMps2?: number;
  decelerationMps2?: number;
  vehicleCars?: Record<string, unknown>[];
};

export default function VehicleFields({ form }: { form: FormInstance }) {
  const [sources, setSources] = useState<(Siding & { label: string })[]>([]);
  const [sourceId, setSourceId] = useState<string>();
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState<{ type: 'info' | 'error' | 'success'; message: string }>();
  async function loadSources() {
    setLoading(true);
    setNotice(undefined);
    try {
      const [sidings, depots] = await Promise.all([
        api<Siding[]>('/admin/catalog/sidings'),
        api<{ id: string; name: string }[]>('/admin/catalog/depots'),
      ]);
      const depotNames = new Map(depots.map((depot) => [depot.id, depot.name]));
      const configured = sidings
        .filter(
          (s) => s.depotId !== null && Array.isArray(s.vehicleCars) && s.vehicleCars.length > 0,
        )
        .map((s) => ({
          ...s,
          label: `${depotNames.get(s.depotId!) || s.depotId} / ${s.name || s.id} · ${s.vehicleCars!.length} 节`,
        }));
      setSources(configured);
      setSourceId(undefined);
      if (!configured.length)
        setNotice({
          type: 'info',
          message: '没有找到已配置车辆编组的车厂轨道。可先在 MTR 中配置车辆，或使用下方表单。',
        });
    } catch (error) {
      setNotice({
        type: 'error',
        message: error instanceof Error ? error.message : '读取游戏轨道失败',
      });
    } finally {
      setLoading(false);
    }
  }

  function importSource() {
    const source = sources.find((s) => s.id === sourceId);
    if (!source?.vehicleCars) return;
    if (source.vehicleCars.length > 16) {
      setNotice({ type: 'error', message: '该轨道的编组超过 TAXILink 支持的 16 节。' });
      return;
    }
    form.setFieldValue(
      ['config', 'vehicleCars'],
      source.vehicleCars.map((car) => ({
        vehicleId: typeof car.vehicleId === 'string' ? car.vehicleId : '',
        ...Object.fromEntries(
          fields.map(([key]) => [key, typeof car[key] === 'number' ? car[key] : undefined]),
        ),
      })),
    );
    for (const key of ['accelerationMps2', 'decelerationMps2'] as const) {
      if (typeof source[key] === 'number') form.setFieldValue(['config', key], source[key]);
    }
    setNotice({ type: 'success', message: '已载入轨道编组。检查载客人数和行驶参数后保存车型。' });
  }

  return (
    <Space direction="vertical" size="middle" style={{ width: '100%' }}>
      <Card size="small" title="从车厂轨道读取">
        <Space wrap>
          <Button loading={loading} onClick={loadSources}>
            读取游戏轨道
          </Button>
          {sources.length > 0 && (
            <>
              <Select
                style={{ minWidth: 235 }}
                showSearch
                optionFilterProp="label"
                placeholder="选择已有车辆编组"
                value={sourceId}
                onChange={setSourceId}
                options={sources.map((s) => ({ value: s.id, label: s.label }))}
              />
              <Button type="primary" disabled={!sourceId} onClick={importSource}>
                载入并替换当前编组
              </Button>
            </>
          )}
        </Space>
        <p>读取的是 MTR 保存的轨道编组。保存车型供平台派车使用，游戏轨道配置由 MTR 管理。</p>
        {notice && <Alert showIcon type={notice.type} message={notice.message} />}
      </Card>
      <Row gutter={12}>
        {(
          [
            ['maxSpeedKmh', '最高速度（km/h）', 1, 160],
            ['accelerationMps2', '加速度（m/s²）', 0.1, 4],
            ['decelerationMps2', '减速度（m/s²）', 0.1, 4],
          ] as const
        ).map(([key, label, min, max]) => (
          <Col xs={24} sm={8} key={key}>
            <Form.Item
              name={['config', key]}
              label={label}
              rules={[{ required: true, type: 'number', min, max }]}
            >
              <InputNumber min={min} max={max} step={0.1} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        ))}
      </Row>
      <Form.List name={['config', 'vehicleCars']}>
        {(cars, { add, remove }) => (
          <Space direction="vertical" style={{ width: '100%' }}>
            {cars.map((car, index) => (
              <Card
                size="small"
                key={car.key}
                title={`第 ${index + 1} 节车厢`}
                extra={
                  <Button
                    danger
                    type="link"
                    disabled={cars.length <= 1}
                    onClick={() => remove(car.name)}
                  >
                    移除
                  </Button>
                }
              >
                <Form.Item
                  name={[car.name, 'vehicleId']}
                  label="车辆资源 ID"
                  rules={[{ required: true, whitespace: true, max: 256 }]}
                >
                  <Input />
                </Form.Item>
                <Row gutter={12}>
                  {fields.map(([key, label, min, max]) => (
                    <Col xs={24} sm={12} key={key}>
                      <Form.Item
                        name={[car.name, key]}
                        label={label}
                        rules={[
                          {
                            required: true,
                            type: key === 'capacity' ? 'integer' : 'number',
                            min,
                            max,
                          },
                        ]}
                      >
                        <InputNumber
                          min={min}
                          max={max}
                          step={key === 'capacity' ? 1 : 0.1}
                          style={{ width: '100%' }}
                        />
                      </Form.Item>
                    </Col>
                  ))}
                </Row>
              </Card>
            ))}
            <Button block disabled={cars.length >= 16} onClick={() => add({ ...defaultCar })}>
              添加车厢
            </Button>
          </Space>
        )}
      </Form.List>
    </Space>
  );
}
