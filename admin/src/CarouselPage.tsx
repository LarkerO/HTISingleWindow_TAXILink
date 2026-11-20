import { useEffect, useState } from 'react';
import {
  AutoComplete,
  App,
  Button,
  Card,
  Form,
  Input,
  InputNumber,
  Select,
  Space,
  Switch,
} from 'antd';
import MediaField from './MediaField';
import { api } from './api';
export default function CarouselPage() {
  const [announcements, setAnnouncements] = useState<{ value: string; label: string }[]>([]);
  useEffect(() => {
    api('/admin/announcements')
      .then((d) => setAnnouncements(d.map((i: any) => ({ value: i.id, label: i.title }))))
      .catch(() => {});
  }, []);
  const [form] = Form.useForm();
  const { message } = App.useApp();
  useEffect(() => {
    api('/admin/carousel')
      .then((d) => form.setFieldsValue(d))
      .catch((e) => message.error(e.message));
  }, []);
  return (
    <Card title="乘客端轮播卡片">
      <p>
        按列表顺序轮播；尺寸为逻辑像素，窄屏会等比例缩小。上传或粘贴已有 COS
        图片地址，公告权限仍由公告页面检查。
      </p>
      <Form
        form={form}
        layout="vertical"
        onFinish={async (v) => {
          try {
            await api('/admin/carousel', v);
            message.success('已保存');
          } catch (e) {
            message.error((e as Error).message);
          }
        }}
      >
        <Form.List name="items">
          {(fields, { add, remove, move }) => (
            <>
              <Space>
                <Button
                  onClick={() =>
                    add({
                      title: '',
                      image: '',
                      enabled: true,
                      placement: 'home',
                      width: 600,
                      height: 180,
                      type: 'url',
                      target: '',
                    })
                  }
                >
                  新增卡片
                </Button>
                <Button type="primary" htmlType="submit">
                  保存全部
                </Button>
              </Space>
              {fields.map(({ key, name }) => (
                <Card
                  key={key}
                  size="small"
                  style={{ marginTop: 16 }}
                  title={`卡片 ${name + 1}`}
                  extra={
                    <Space>
                      <Button disabled={!name} onClick={() => move(name, name - 1)}>
                        上移
                      </Button>
                      <Button danger onClick={() => remove(name)}>
                        删除
                      </Button>
                    </Space>
                  }
                >
                  <Form.Item name={[name, 'title']} label="图片说明" rules={[{ required: true }]}>
                    <Input maxLength={100} />
                  </Form.Item>
                  <Form.Item name={[name, 'enabled']} label="启用" valuePropName="checked">
                    <Switch />
                  </Form.Item>
                  <Form.Item name={[name, 'placement']} label="位置">
                    <Select
                      options={[
                        { value: 'home', label: '主页按钮与页尾之间' },
                        { value: 'trip', label: '行程信息卡底部' },
                      ]}
                    />
                  </Form.Item>
                  <Form.Item name={[name, 'image']} label="图片" rules={[{ required: true }]}>
                    <MediaField purpose="announcement" />
                  </Form.Item>
                  <Space>
                    <Form.Item name={[name, 'width']} label="展示宽度">
                      <InputNumber min={40} max={1600} />
                    </Form.Item>
                    <Form.Item name={[name, 'height']} label="展示高度">
                      <InputNumber min={40} max={1600} />
                    </Form.Item>
                  </Space>
                  <Form.Item name={[name, 'type']} label="点击行为">
                    <Select
                      options={[
                        { value: 'announcement', label: '公告页面' },
                        { value: 'url', label: '新窗口打开 URL' },
                      ]}
                    />
                  </Form.Item>
                  <Form.Item
                    name={[name, 'target']}
                    label="公告 ID 或完整 HTTP/HTTPS URL"
                    rules={[{ required: true }]}
                  >
                    <AutoComplete options={announcements} placeholder="选择公告或输入完整 URL" />
                  </Form.Item>
                </Card>
              ))}
            </>
          )}
        </Form.List>
      </Form>
    </Card>
  );
}
