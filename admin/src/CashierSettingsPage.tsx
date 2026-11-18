import { useEffect, useState } from 'react';
import { Alert, Button, Card, Form, Input, Select, Switch, message } from 'antd';
import { api } from './api';

type Company = { id: string; name: string };
export default function CashierSettingsPage({
  companies,
  platformAdmin,
}: {
  companies: Company[];
  platformAdmin: boolean;
}) {
  const [scope, setScope] = useState(platformAdmin ? 'default' : companies[0]?.id || '');
  const [busy, setBusy] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [form] = Form.useForm();
  const endpoint = '/admin/cashier' + (scope === 'default' ? '' : '/' + scope);
  useEffect(() => {
    let active = true;
    setLoaded(false);
    form.resetFields();
    if (scope)
      api(endpoint)
        .then((data) => {
          if (active) {
            form.setFieldsValue({ environment: 'sandbox', ...data });
            setLoaded(true);
          }
        })
        .catch((e) => message.error(e.message));
    return () => {
      active = false;
    };
  }, [scope, endpoint, form]);
  async function save(values: Record<string, unknown>) {
    setBusy(true);
    try {
      const result = await api(endpoint, values);
      form.setFieldsValue({ ...result, privateKey: '', keyId: '' });
      message.success('商户配置已保存');
    } catch (e) {
      message.error(e instanceof Error ? e.message : '保存失败');
    } finally {
      setBusy(false);
    }
  }
  return (
    <Card title="统一收银台商户配置">
      <Alert
        showIcon
        type="info"
        message="承运公司启用的专属商户优先；未配置及承运公司尚未确定的预付订单使用总商户。退款始终使用原交易商户。正式车费按整游戏币向上取整。"
        style={{ marginBottom: 20 }}
      />
      <Select
        style={{ width: '100%', marginBottom: 20 }}
        value={scope}
        onChange={setScope}
        options={[
          ...(platformAdmin ? [{ value: 'default', label: '总商户（兜底）' }] : []),
          ...companies.map((c) => ({ value: c.id, label: c.name })),
        ]}
      />
      <Form key={scope} form={form} layout="vertical" onFinish={save} disabled={!loaded || busy}>
        <Form.Item name="enabled" label="启用此商户" valuePropName="checked">
          <Switch />
        </Form.Item>
        <Form.Item
          name="origin"
          label="收银台来源地址"
          extra="例如 https://cashier.example，不含路径"
        >
          <Input />
        </Form.Item>
        <Form.Item name="merchant" label="商户号">
          <Input />
        </Form.Item>
        <Form.Item name="environment" label="交易环境">
          <Select
            options={[
              { value: 'sandbox', label: '沙箱' },
              { value: 'live', label: '正式' },
            ]}
          />
        </Form.Item>
        <Form.Item name="keyId" label="API Key" extra="留空保留已保存的密钥">
          <Input.Password autoComplete="new-password" />
        </Form.Item>
        <Form.Item
          name="privateKey"
          label="商户 SM2 私钥 PEM"
          extra="留空保留；私钥仅在服务端保存，不会返回浏览器"
        >
          <Input.TextArea rows={5} />
        </Form.Item>
        <Form.Item name="platformKey" label="平台 SM2 公钥 PEM">
          <Input.TextArea rows={4} />
        </Form.Item>
        <Form.Item
          name="returnUrl"
          label="付款回跳地址"
          extra="填写乘客端 /payment-return 地址，并在收银台商户后台登记其来源"
        >
          <Input />
        </Form.Item>
        <Button type="primary" htmlType="submit" loading={busy}>
          保存配置
        </Button>
      </Form>
    </Card>
  );
}
