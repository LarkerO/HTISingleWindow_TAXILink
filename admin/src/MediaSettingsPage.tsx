import { useEffect, useState } from 'react';
import { Alert, App, Button, Card, Form, Input, Select, Switch } from 'antd';
import { api } from './api';

export default function MediaSettingsPage() {
  const [form] = Form.useForm();
  const [settings, setSettings] = useState<any>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [provider, setProvider] = useState('cos');
  const names: Record<string, string> = {
    cos: '腾讯云 COS',
    oss: '阿里云 OSS',
    qiniu: '七牛云 Kodo',
    s3: 'AWS S3',
  };
  const { message } = App.useApp();
  useEffect(() => {
    let current = true;
    api('/admin/media/settings?provider=' + provider)
      .then((d) => {
        if (!current) return;
        setSettings(d);
        form.setFieldsValue({
          ...d,
          secretId: '',
          secretKey: '',
          activate: d.activeProvider === provider,
        });
      })
      .catch((e) => setError(e.message));
    return () => {
      current = false;
    };
  }, [form, provider]);
  return (
    <Card title="对象存储设置">
      <Select
        aria-label="对象存储服务"
        value={provider}
        disabled={busy}
        style={{ width: 240, marginBottom: 20 }}
        options={Object.entries(names).map(([value, label]) => ({ value, label }))}
        onChange={(value) => {
          setSettings(null);
          setError('');
          setProvider(value);
        }}
      />
      {error && <Alert type="error" message={error} />}
      {settings && (
        <>
          <Alert
            type={settings.configured ? 'success' : 'warning'}
            message={
              (settings.configured
                ? '配置已填写，请上传图片验证连接'
                : names[provider] + ' 尚未配置') +
              ' · 当前默认：' +
              (names[settings.activeProvider] ?? '腾讯云 COS')
            }
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
                const d = await api('/admin/media/settings', { ...v, provider });
                setSettings(d);
                form.setFieldsValue({ ...d, secretId: '', secretKey: '' });
                message.success('对象存储设置已保存');
              } catch (e) {
                message.error(e instanceof Error ? e.message : '保存失败');
              } finally {
                setBusy(false);
              }
            }}
          >
            <Form.Item
              name="bucket"
              label={provider === 'cos' ? '存储桶（含 APPID 后缀）' : '存储桶'}
              rules={[{ required: true }]}
            >
              <Input placeholder="images-1234567890" />
            </Form.Item>
            <Form.Item name="region" label="地域" rules={[{ required: true }]}>
              <Input
                placeholder={
                  provider === 'cos'
                    ? 'ap-guangzhou'
                    : provider === 'oss'
                      ? 'cn-hangzhou'
                      : provider === 'qiniu'
                        ? 'z0 / z1 / z2 / na0 / as0 / cn-east-2'
                        : 'us-east-1'
                }
              />
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
            <Form.Item
              name="publicUrl"
              label={
                provider === 'qiniu' ? '公开 HTTPS 域名（七牛必填）' : '公开 HTTPS 域名（可选）'
              }
              rules={provider === 'qiniu' ? [{ required: true }] : []}
            >
              <Input placeholder="留空使用默认 COS 域名" />
            </Form.Item>
            <Form.Item name="activate" label="设为默认上传服务" valuePropName="checked">
              <Switch />
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
