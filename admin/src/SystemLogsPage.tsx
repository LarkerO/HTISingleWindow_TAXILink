import { useEffect, useState } from 'react';
import {
  Alert,
  Button,
  Card,
  Checkbox,
  DatePicker,
  Input,
  Select,
  Space,
  Table,
  Tag,
  Typography,
} from 'antd';
import { api } from './api';

export default function SystemLogsPage() {
  const [filters, setFilters] = useState<Record<string, string>>({ hideRoutine: '1' });
  const [rows, setRows] = useState<any[]>([]),
    [error, setError] = useState(''),
    [busy, setBusy] = useState(false),
    [revision, setRevision] = useState(0);
  useEffect(() => {
    let current = true;
    setBusy(true);
    api('/admin/system_logs?' + new URLSearchParams(filters))
      .then((d) => {
        if (current) {
          setRows(d);
          setError('');
        }
      })
      .catch((e) => {
        if (current) setError(e.message);
      })
      .finally(() => {
        if (current) setBusy(false);
      });
    return () => {
      current = false;
    };
  }, [filters, revision]);
  const update = (key: string, value?: string) => setFilters((f) => ({ ...f, [key]: value ?? '' }));
  const labels: Record<string, string> = {
    connected: '连接成功',
    disconnected: '断开连接',
    connection_rejected: '连接拒绝',
    command_sent: '指令下发',
    command_finished: '指令完成',
    message_rejected: '消息拒绝',
    success: '成功',
    timeout: '超时',
    mod_error: '模组拒绝',
    send_failed: '下发失败',
    handshake_invalid: '握手认证失败',
    session_required: '会话失效',
    origin_or_path: '来源或路径错误',
    mod: '模组',
    passenger: '乘客端',
  };
  const select = (key: string, placeholder: string, values: string[]) => (
    <Select
      allowClear
      aria-label={placeholder}
      placeholder={placeholder}
      style={{ width: 145 }}
      value={filters[key] || undefined}
      options={values.map((value) => ({ value, label: labels[value] ?? value }))}
      onChange={(v) => update(key, v)}
    />
  );
  return (
    <Card
      title="系统日志"
      extra={
        <Button loading={busy} onClick={() => setRevision((x) => x + 1)}>
          刷新
        </Button>
      }
    >
      <Alert
        type="info"
        showIcon
        message="按请求 ID 关联下发与完成记录；常规轮询日志不代表故障。默认隐藏常规同步成功记录，关键词搜索会包含这些记录。最多保留最近 1000 条。"
        style={{ marginBottom: 16 }}
      />
      <Space wrap style={{ marginBottom: 16 }}>
        <Checkbox
          checked={filters.hideRoutine === '1'}
          onChange={(e) => update('hideRoutine', e.target.checked ? '1' : '0')}
        >
          隐藏常规同步成功记录
        </Checkbox>
        <Input.Search
          aria-label="搜索系统日志"
          placeholder="请求 / 连接 ID、指令、结果代码"
          allowClear
          onSearch={(v) => update('q', v)}
          style={{ width: 300 }}
        />
        {select('channel', '通道', ['mod', 'passenger'])}
        {select('event', '事件', [
          'connected',
          'disconnected',
          'connection_rejected',
          'command_sent',
          'command_finished',
          'message_rejected',
        ])}
        {select('result', '结果', [
          'success',
          'timeout',
          'mod_error',
          'send_failed',
          'handshake_invalid',
          'session_required',
          'origin_or_path',
        ])}
        <Input
          aria-label="精确筛选指令"
          placeholder="指令，如 taxi.quote"
          value={filters.command ?? ''}
          onChange={(e) => update('command', e.target.value)}
          style={{ width: 200 }}
        />
        <DatePicker.RangePicker
          showTime
          onChange={(v) =>
            setFilters((f) => ({
              ...f,
              from: v?.[0] ? String(v[0].valueOf()) : '',
              to: v?.[1] ? String(v[1].valueOf()) : '',
            }))
          }
        />
      </Space>
      {error && <Alert type="error" message={error} />}
      <Table
        rowKey="id"
        dataSource={rows}
        loading={busy}
        pagination={{ pageSize: 25 }}
        scroll={{ x: 1500 }}
        columns={[
          {
            title: '时间',
            dataIndex: 'created_at',
            render: (v) => new Date(Number(v)).toLocaleString('zh-CN'),
          },
          { title: '通道', dataIndex: 'channel', render: (v) => labels[v] ?? v },
          { title: '事件', dataIndex: 'event', render: (v) => labels[v] ?? v },
          { title: '连接 ID', dataIndex: 'connection_id' },
          { title: '指令', dataIndex: 'command' },
          {
            title: '请求 ID',
            dataIndex: 'request_id',
            render: (v) => <Typography.Text copyable>{v}</Typography.Text>,
          },
          { title: '耗时', dataIndex: 'duration_ms', render: (v) => (v == null ? '—' : v + ' ms') },
          { title: '结果', dataIndex: 'result', render: (v) => <Tag>{labels[v] ?? v}</Tag> },
          { title: '中文说明', dataIndex: 'explanation', width: 360 },
        ]}
      />
    </Card>
  );
}
