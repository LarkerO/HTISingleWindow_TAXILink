import { useEffect, useState } from 'react';
import { Alert, App, Button, Card, Form, Input } from 'antd';
import { api } from './api';

export default function MediaSettingsPage() {
  const [form] = Form.useForm();
  const [settings, setSettings] = useState<any>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const { message } = App.useApp();
  useEffect(() => {
    api('/admin/media/settings')
      .then((d) => {
        setSettings(d);
        form.setFieldsValue({ ...d, secretId: '', secretKey: '' });
      })
      .catch((e) => setError(e.message));
  }, [form]);
  return (
    <Card title="腾讯云 COS 图片存储" loading={!settings && !error}>
      {error && <Alert type="error" message={error} />}
      {settings && (
        <>
          <Alert
            type={settings.configured ? 'success' : 'warning'}
            message={settings.configured ? '配置已填写，请上传图片验证连接' : 'COS 尚未配置'}
            description="保存后即时生效，无需编辑 .env 或重启。密钥加密保存在服务端，留空保留现有值。存储桶图片需允许公开读取；更换桶或域名不会迁移已有图片。"
          />
          <Form
            form={form}
            layout="vertical"
            disabled={busy}
            style={{ marginTop: 20 }}
            onFinish={async (v) => {
              setBusy(true);
              try {
                const d = await api('/admin/media/settings', v);
                setSettings(d);
                form.setFieldsValue({ ...d, secretId: '', secretKey: '' });
                message.success('COS 设置已保存');
              } catch (e) {
                message.error(e instanceof Error ? e.message : '保存失败');
              } finally {
                setBusy(false);
              }
            }}
          >
            <Form.Item name="bucket" label="存储桶（含 APPID 后缀）" rules={[{ required: true }]}>
              <Input placeholder="images-1234567890" />
            </Form.Item>
            <Form.Item name="region" label="地域" rules={[{ required: true }]}>
              <Input placeholder="ap-guangzhou" />
            </Form.Item>
            <Form.Item name="secretId" label="SecretId">
              <Input.Password
                autoComplete="new-password"
                placeholder={settings.secretIdConfigured ? '已配置，留空保留' : '请输入 SecretId'}
              />
            </Form.Item>
            <Form.Item name="secretKey" label="SecretKey">
              <Input.Password
                autoComplete="new-password"
                placeholder={settings.secretKeyConfigured ? '已配置，留空保留' : '请输入 SecretKey'}
              />
            </Form.Item>
            <Form.Item name="publicUrl" label="公开 HTTPS 域名（可选）">
              <Input placeholder="留空使用默认 COS 域名" />
            </Form.Item>
            <Button type="primary" htmlType="submit" loading={busy}>
              保存
            </Button>
          </Form>
        </>
      )}
    </Card>
  );
}
