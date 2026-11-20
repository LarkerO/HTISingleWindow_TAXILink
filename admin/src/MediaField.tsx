import { useState, useEffect } from 'react';
import { Button, Input, Space, Upload, Image, App, Select } from 'antd';
import { api, uploadImage } from './api';

export default function MediaField({
  value = '',
  onChange,
  purpose,
  company = '',
}: {
  value?: string;
  onChange?: (url: string) => void;
  purpose: string;
  company?: string;
}) {
  const [library, setLibrary] = useState<{ url: string; name: string; provider: string }[]>([]);
  const [busy, setBusy] = useState(false);
  const [provider, setProvider] = useState('');
  const [providers, setProviders] = useState<{ id: string; name: string }[]>([]);
  useEffect(() => {
    api('/media/providers')
      .then((d) => setProviders(Array.isArray(d.items) ? d.items : []))
      .catch(() => {});
  }, []);
  const { message } = App.useApp();
  return (
    <Space direction="vertical" style={{ width: '100%' }}>
      {value && <Image src={value} width={100} height={70} style={{ objectFit: 'contain' }} />}
      <Space>
        <Select
          aria-label="本次上传服务"
          value={provider}
          onChange={setProvider}
          disabled={busy}
          style={{ width: 180 }}
          options={[
            { value: '', label: '使用默认服务' },
            ...providers.map((p) => ({ value: p.id, label: p.name })),
          ]}
        />
        <Upload
          accept="image/png,image/jpeg,image/webp,image/gif"
          showUploadList={false}
          disabled={busy}
          beforeUpload={(file) => {
            setBusy(true);
            uploadImage(file, purpose, company, provider)
              .then((d) => onChange?.(d.url))
              .catch((e) => message.error(e.message))
              .finally(() => setBusy(false));
            return false;
          }}
        >
          <Button loading={busy}>上传图片</Button>
        </Upload>
        <Button onClick={() => onChange?.('')}>清除图片</Button>
      </Space>
      {purpose === 'announcement' && (
        <Select
          showSearch
          allowClear
          placeholder="选择已上传图片（或在下方填写原有 COS 地址）"
          style={{ width: '100%' }}
          onOpenChange={(open) => {
            if (open)
              api('/admin/media/library')
                .then((d) => setLibrary(d.items))
                .catch(() => {});
          }}
          options={library.map((i) => ({ value: i.url, label: i.provider + ' · ' + i.name }))}
          onChange={(v) => {
            if (v) onChange?.(v);
          }}
        />
      )}
      <Input
        value={value}
        onChange={(e) => onChange?.(e.target.value)}
        placeholder="HTTPS 图片地址；支持 PNG/JPEG/WebP/GIF，最多 5MB"
      />
    </Space>
  );
}
