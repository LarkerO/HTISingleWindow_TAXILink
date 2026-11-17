import { useEffect, useState } from 'react';
import { App, Button, Card, Form, Input, Switch } from 'antd';
import { api } from './api';
import { blueMapRoot } from '../../shared/bluemap';

type Settings = { mapId: string; allow3d: boolean };

export function BlueMapSettingsPage() {
  const [form] = Form.useForm<Settings>();
  const [loading, setLoading] = useState(true);
  const [ready, setReady] = useState(false);
  const { message } = App.useApp();
  useEffect(() => {
    api<{ mapId: string | null; allow3d: boolean }>('/map-settings')
      .then((settings) => {
        form.setFieldsValue({
          ...settings,
          mapId: settings.mapId ?? blueMapRoot.split('/').at(-1),
        });
        setReady(true);
      })
      .catch((error) => message.error(error.message))
      .finally(() => setLoading(false));
  }, [form, message]);

  async function save(settings: Settings) {
    setLoading(true);
    try {
      const response = await fetch(
        blueMapRoot.slice(0, blueMapRoot.lastIndexOf('/') + 1) +
          encodeURIComponent(settings.mapId) +
          '/settings.json',
      );
      if (!response.ok || !(await response.json()).lowres) {
        throw new Error('无法读取此地图的瓦片配置，请检查地图 ID 和 BlueMap 服务');
      }
      await api('/admin/map-settings', settings);
      message.success('BlueMap 设置已保存');
    } catch (error) {
      message.error(error instanceof Error ? error.message : '保存失败');
    } finally {
      setLoading(false);
    }
  }

  return (
    <Card title="BlueMap 设置">
      <Form form={form} layout="vertical" onFinish={save} disabled={!ready || loading}>
        <Form.Item
          name="mapId"
          label="地图 ID"
          rules={[
            { required: true, message: '请输入地图 ID' },
            { pattern: /^[a-zA-Z0-9_-]{1,128}$/, message: '请使用字母、数字、下划线或连字符' },
          ]}
        >
          <Input />
        </Form.Item>
        <Form.Item name="allow3d" label="允许切换到 3D" valuePropName="checked">
          <Switch />
        </Form.Item>
        <Button type="primary" htmlType="submit" loading={loading}>
          保存
        </Button>
      </Form>
    </Card>
  );
}
