import { useEffect, useState } from 'react';
import { Alert, Card, Descriptions } from 'antd';
import { api } from './api';
export default function MediaSettingsPage() {
  const [settings, setSettings] = useState<any>(null),
    [error, setError] = useState('');
  useEffect(() => {
    api('/admin/media/settings')
      .then(setSettings)
      .catch((e) => setError(e.message));
  }, []);
  return (
    <Card title="腾讯云 COS 图片存储" loading={!settings && !error}>
      {error && <Alert type="error" message={error} />}
      {settings && (
        <>
          <Alert
            type={settings.configured ? 'success' : 'warning'}
            message={settings.configured ? 'COS 配置已填写，请上传图片验证连接' : 'COS 尚未配置'}
            description="在后端 .env 配置 COS_BUCKET、COS_REGION、COS_SECRET_ID、COS_SECRET_KEY，重启后生效。COS_PUBLIC_URL 可填写 HTTPS CDN 根地址。图片需允许公开读取；密钥仅保存在服务端。"
          />
          <Descriptions
            column={1}
            style={{ marginTop: 20 }}
            items={[
              { key: 'bucket', label: '存储桶', children: settings.bucket || '未配置' },
              { key: 'region', label: '地域', children: settings.region || '未配置' },
              { key: 'url', label: '公开域名', children: settings.publicUrl || '默认 COS 域名' },
              {
                key: 'limit',
                label: '图片限制',
                children: 'PNG / JPEG / WebP / GIF，最多 5MB，长宽不超过 8192px',
              },
            ]}
          />
        </>
      )}
    </Card>
  );
}
