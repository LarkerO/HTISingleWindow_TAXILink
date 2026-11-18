import { useState } from 'react';
import { Button, Input, Space, Upload, Image, App } from 'antd';
import { uploadImage } from './api';

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
  const [busy, setBusy] = useState(false);
  const { message } = App.useApp();
  return (
    <Space direction="vertical" style={{ width: '100%' }}>
      {value && <Image src={value} width={100} height={70} style={{ objectFit: 'contain' }} />}
      <Space>
        <Upload
          accept="image/png,image/jpeg,image/webp,image/gif"
          showUploadList={false}
          disabled={busy}
          beforeUpload={(file) => {
            setBusy(true);
            uploadImage(file, purpose, company)
              .then((d) => onChange?.(d.url))
              .catch((e) => message.error(e.message))
              .finally(() => setBusy(false));
            return false;
          }}
        >
          <Button loading={busy}>上传图片至 COS</Button>
        </Upload>
        <Button onClick={() => onChange?.('')}>清除图片</Button>
      </Space>
      <Input
        value={value}
        onChange={(e) => onChange?.(e.target.value)}
        placeholder="HTTPS 图片地址；支持 PNG/JPEG/WebP/GIF，最多 5MB"
      />
    </Space>
  );
}
