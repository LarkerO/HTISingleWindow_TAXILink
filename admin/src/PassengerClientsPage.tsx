import { useEffect, useState } from 'react';
import { Alert, App, Button, Card, Form, Input } from 'antd';
import { api } from './api';

export default function PassengerClientsPage() {
  const [form] = Form.useForm();
  const [busy, setBusy] = useState(true);
  const [ready, setReady] = useState(false);
  const { message } = App.useApp();
  useEffect(() => {
    api('/admin/passenger-clients')
      .then((d) => {
        form.setFieldsValue({ origins: d.origins.join('\n') });
        setReady(true);
      })
      .catch((e) => message.error(e.message))
      .finally(() => setBusy(false));
  }, [form, message]);
  return (
    <Card title="乘客端接入">
      <Alert
        type="info"
        showIcon
        message="多个乘客端可同时使用"
        description="每行填写一个完整源地址，例如 https://passv2.example.com，不带末尾斜杠。各站点需代理 /api/、/ws 和 /bluemap/，并在 Portal 登记各自的 /api/auth/callback。原主乘客端与环境变量中的地址始终保留。保存后即时生效。"
      />
      <Form
        form={form}
        layout="vertical"
        disabled={busy || !ready}
        style={{ marginTop: 20 }}
        onFinish={async (v) => {
          setBusy(true);
          try {
            const d = await api('/admin/passenger-clients', {
              origins: v.origins.split(/\s+/).filter(Boolean),
            });
            form.setFieldsValue({ origins: d.origins.join('\n') });
            message.success('乘客端接入设置已保存');
          } catch (e) {
            message.error(e instanceof Error ? e.message : '保存失败');
          } finally {
            setBusy(false);
          }
        }}
      >
        <Form.Item name="origins" label="允许的乘客端地址">
          <Input.TextArea rows={8} />
        </Form.Item>
        <Button type="primary" htmlType="submit" loading={busy}>
          保存
        </Button>
      </Form>
    </Card>
  );
}
