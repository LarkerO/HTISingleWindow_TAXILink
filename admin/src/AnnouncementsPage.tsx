import { useEffect, useState } from 'react';
import {
  App,
  Button,
  Card,
  Checkbox,
  Drawer,
  Form,
  Input,
  Space,
  Switch,
  Table,
  Tag,
  Tabs,
} from 'antd';
import { api } from './api';
import MediaField from './MediaField';
import { renderMarkdown } from '../../shared/markdown';

export default function AnnouncementsPage() {
  const [rows, setRows] = useState<any[]>([]),
    [edit, setEdit] = useState<any>(null);
  const [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(false);
  const [form] = Form.useForm();
  const { message } = App.useApp();
  const content = Form.useWatch('markdown', form) || '';
  async function load() {
    setLoading(true);
    try {
      setRows(await api('/admin/announcements'));
    } catch (e) {
      message.error((e as Error).message);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    load();
  }, []);
  function open(row?: any) {
    form.resetFields();
    form.setFieldsValue(
      row
        ? {
            ...row,
            placements: JSON.parse(row.placements),
            published: !!Number(row.published),
            listed: !!Number(row.listed),
            login_required: !!Number(row.login_required),
          }
        : {
            title: '',
            markdown: '',
            placements: [],
            published: false,
            listed: true,
            login_required: false,
          },
    );
    setEdit(row || {});
  }
  async function save() {
    const values = await form.validateFields();
    setBusy(true);
    try {
      await api(
        '/admin/announcements' + (edit.id ? '/' + edit.id : ''),
        values,
        edit.id ? 'PATCH' : 'POST',
      );
      setEdit(null);
      message.success('公告已保存');
      await load();
    } catch (e) {
      message.error((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <Card
      title="公告中心"
      extra={
        <Button type="primary" onClick={() => open()}>
          新建公告
        </Button>
      }
    >
      <Table
        rowKey="id"
        dataSource={rows}
        loading={loading}
        columns={[
          { title: '标题', dataIndex: 'title' },
          {
            title: '状态',
            render: (_, row) => (
              <Tag color={Number(row.published) ? 'green' : 'default'}>
                {Number(row.published) ? '已发布' : '草稿 / 已下架'}
              </Tag>
            ),
          },
          { title: '列表显示', render: (_, row) => (Number(row.listed) ? '是' : '否') },
          { title: '登录查看', render: (_, row) => (Number(row.login_required) ? '是' : '否') },
          {
            title: '更新时间',
            render: (_, row) => new Date(row.updated_at * 1000).toLocaleString('zh-CN'),
          },
          { title: '操作', render: (_, row) => <Button onClick={() => open(row)}>编辑</Button> },
        ]}
      />
      <Drawer
        title={edit?.id ? '编辑公告' : '新建公告'}
        width={760}
        open={edit !== null}
        onClose={() => setEdit(null)}
        extra={
          <Button type="primary" loading={busy} onClick={() => save().catch(() => {})}>
            保存
          </Button>
        }
      >
        <Form form={form} layout="vertical">
          <Form.Item name="title" label="标题" rules={[{ required: true, max: 120 }]}>
            <Input />
          </Form.Item>
          <Form.Item name="placements" label="页面通知栏（独立于列表可见性）">
            <Checkbox.Group
              options={[
                { label: '下单页', value: 'booking' },
                { label: '订单页', value: 'orders' },
                { label: '个人中心', value: 'profile' },
              ]}
            />
          </Form.Item>
          <Space wrap>
            <Form.Item name="published" label="发布" valuePropName="checked">
              <Switch />
            </Form.Item>
            <Form.Item name="listed" label="在公告列表显示" valuePropName="checked">
              <Switch />
            </Form.Item>
            <Form.Item name="login_required" label="需要登录查看" valuePropName="checked">
              <Switch />
            </Form.Item>
          </Space>
          <Tabs
            items={[
              {
                key: 'edit',
                label: 'Markdown 编写',
                forceRender: true,
                children: (
                  <Form.Item name="markdown" rules={[{ required: true }]}>
                    <Input.TextArea rows={16} maxLength={200000} />
                  </Form.Item>
                ),
              },
              {
                key: 'preview',
                label: '预览',
                children: (
                  <article
                    className="markdown-body"
                    dangerouslySetInnerHTML={{ __html: renderMarkdown(content) }}
                  />
                ),
              },
            ]}
          />
          <Card size="small" title="正文配图">
            <MediaField
              purpose="announcement"
              onChange={(url) => {
                if (url)
                  form.setFieldValue(
                    'markdown',
                    (form.getFieldValue('markdown') || '') + '\n\n![公告配图](' + url + ')\n',
                  );
              }}
            />
          </Card>
        </Form>
      </Drawer>
    </Card>
  );
}
