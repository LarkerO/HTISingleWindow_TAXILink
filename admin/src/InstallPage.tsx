import { useEffect, useState } from 'react';
import { Alert, Button, Card, Form, Input, Space, Spin, Typography } from 'antd';

type Status = {
  state: 'setup' | 'restart' | 'installed';
  requirements: Record<string, boolean>;
  local: boolean;
};

const adminOrigin = window.location.origin;
const callback = '/api/auth/callback';
const errors: Record<string, string> = {
  portal: 'Portal 授权失败。请检查客户端凭据、回调地址和权限后重试。',
  keys: 'SM2 密钥生成或双向签名校验失败。请检查 OpenSSL 和安装目录权限。',
  database: '数据库初始化失败。请检查数据库权限及是否有并发安装，再重试。',
  publish: '安装配置写入失败。请检查安装目录权限后用同一 Portal 账号重试。',
  account: '请使用上次已授权并开始初始化数据库的 Portal 账号继续安装。',
};
const initialError = new URLSearchParams(window.location.search).get('error');

export default function InstallPage() {
  const [status, setStatus] = useState<Status>();
  const [failure, setFailure] = useState(initialError ? errors[initialError] : undefined);
  const [busy, setBusy] = useState(false);
  const [passenger, setPassenger] = useState('');
  const validOrigin = status?.local
    ? window.location.protocol === 'http:' &&
      ['localhost', '127.0.0.1'].includes(window.location.hostname)
    : window.location.protocol === 'https:';

  function loadStatus() {
    setBusy(true);
    fetch('/api/install/status', { cache: 'no-store' })
      .then(async (response) => {
        if (!response.ok) throw new Error();
        setStatus(await response.json());
      })
      .catch(() => setFailure('无法连接安装服务，请检查后端和 /api/ 反向代理。'))
      .finally(() => setBusy(false));
  }

  useEffect(loadStatus, []);

  async function start(values: Record<string, string>) {
    setFailure(undefined);
    setBusy(true);
    try {
      const response = await fetch('/api/install/start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...values, adminOrigin }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(body.error?.message ?? '无法启动安装，请检查后端。');
      window.location.assign(body.authorizeUrl);
    } catch (error) {
      setFailure(error instanceof Error ? error.message : '安装请求失败。');
      setBusy(false);
    }
  }

  return (
    <main style={{ maxWidth: 720, margin: '40px auto', padding: '0 16px' }}>
      <Card>
        <Space direction="vertical" size="large" style={{ width: '100%' }}>
          <Typography.Title level={2}>安装 TAXILink</Typography.Title>
          {failure && <Alert showIcon type="error" message={failure} />}
          {!status && (busy ? <Spin /> : <Button onClick={loadStatus}>重新检查</Button>)}
          {status?.state === 'installed' && (
            <>
              <Alert showIcon type="success" message="TAXILink 已安装" />
              <Button type="primary" href="/">
                进入管理端
              </Button>
            </>
          )}
          {status?.state === 'restart' && (
            <>
              <Alert
                showIcon
                type="success"
                message="安装成功，首位平台管理员已创建"
                description="请在服务器重启 TAXILink 后端，再点击下方按钮。SM2 文件保存在服务端安装目录。"
              />
              <Button type="primary" loading={busy} onClick={loadStatus}>
                检查服务状态
              </Button>
            </>
          )}
          {status?.state === 'setup' && (
            <>
              <div>
                <Typography.Title level={4}>安装要求</Typography.Title>
                {Object.entries(status.requirements).map(([label, passed]) => (
                  <div key={label}>
                    <Typography.Text type={passed ? 'success' : 'danger'}>
                      {passed ? '通过' : '未通过'} · {label}
                    </Typography.Text>
                  </div>
                ))}
                <Button style={{ marginTop: 12 }} loading={busy} onClick={loadStatus}>
                  重新检查
                </Button>
              </div>
              <Form
                layout="vertical"
                requiredMark={false}
                autoComplete="off"
                onFinish={start}
                initialValues={{
                  dbHost: '127.0.0.1',
                  dbPort: '3306',
                  dbName: 'taxilink',
                  dbUser: 'taxilink',
                  redisUrl: 'tcp://127.0.0.1:6379',
                  portalIssuer: 'https://hydcraft.cn',
                  serverId: 'taxilink-01',
                  dimension: 'minecraft/overworld',
                }}
              >
                <Form.Item
                  label="一次性安装码"
                  name="installCode"
                  rules={[{ required: true }]}
                  extra="在服务器运行 php backend/bin/console.php install-code 获取。"
                >
                  <Input.Password />
                </Form.Item>
                <Form.Item label="管理端地址">
                  <Input readOnly value={adminOrigin} />
                </Form.Item>
                <Form.Item
                  label={status.local ? '乘客端本机 HTTP 地址' : '乘客端 HTTPS 地址'}
                  name="passengerOrigin"
                  rules={[{ required: true }]}
                >
                  <Input
                    placeholder={
                      status.local ? 'http://127.0.0.1:5173' : 'https://taxi.example.com'
                    }
                    onChange={(e) => setPassenger(e.target.value)}
                  />
                </Form.Item>
                {status.local ? (
                  <Alert
                    showIcon
                    type="info"
                    message="本机测试模式"
                    description="使用 SQLite，自动创建数据库。仍使用 Portal 登录、SM2 和真实调度，不加载演示数据。"
                  />
                ) : (
                  <>
                    <Typography.Title level={4}>数据库与 Redis</Typography.Title>
                    <Typography.Paragraph>
                      请先在 MySQL 创建专用空数据库和账号。
                    </Typography.Paragraph>
                    {(
                      [
                        ['dbHost', 'MySQL 主机'],
                        ['dbPort', 'MySQL 端口'],
                        ['dbName', '数据库名称'],
                        ['dbUser', '数据库账号'],
                        ['dbPassword', '数据库密码'],
                        ['redisUrl', 'Redis 连接地址'],
                      ] as const
                    ).map(([name, label]) => (
                      <Form.Item key={name} label={label} name={name} rules={[{ required: true }]}>
                        {name === 'dbPassword' || name === 'redisUrl' ? (
                          <Input.Password />
                        ) : (
                          <Input />
                        )}
                      </Form.Item>
                    ))}
                  </>
                )}
                <Typography.Title level={4}>HydCraft Portal</Typography.Title>
                <Typography.Paragraph>
                  在 Portal 的 /admin/oauth-clients 创建客户端，勾选 profile 和
                  hydroline，将两条回调分别填入一行。 通过本次授权的账号将成为首位平台管理员。
                </Typography.Paragraph>
                <pre style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>
                  {adminOrigin + callback}
                  {'\n'}
                  {(passenger ||
                    (status.local ? 'http://127.0.0.1:5173' : 'https://你的乘客端域名')) + callback}
                </pre>
                {(
                  [
                    ['portalIssuer', 'Portal HTTPS 地址'],
                    ['clientId', 'Client ID'],
                    ['clientSecret', 'Client Secret'],
                    ['serverId', '游戏服务器 ID'],
                    ['dimension', '游戏维度'],
                  ] as const
                ).map(([name, label]) => (
                  <Form.Item key={name} label={label} name={name} rules={[{ required: true }]}>
                    {name === 'clientSecret' ? <Input.Password /> : <Input />}
                  </Form.Item>
                ))}
                <Button
                  type="primary"
                  htmlType="submit"
                  loading={busy}
                  disabled={
                    !validOrigin || Object.values(status.requirements).some((passed) => !passed)
                  }
                >
                  验证配置并前往 Portal 登录
                </Button>
                {!validOrigin && (
                  <Alert
                    style={{ marginTop: 12 }}
                    type="warning"
                    message={
                      status.local
                        ? '请用 localhost 或 127.0.0.1 的 HTTP 地址打开管理端。'
                        : '请使用管理端 HTTPS 地址打开安装页。'
                    }
                  />
                )}
              </Form>
            </>
          )}
        </Space>
      </Card>
    </main>
  );
}
