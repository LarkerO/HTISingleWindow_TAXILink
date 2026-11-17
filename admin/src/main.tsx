import { BlueMapSettingsPage } from './BlueMapSettingsPage';
import { BlueMap } from './BlueMap';
import InstallPage from './InstallPage';
import VehicleFields, { defaultCar } from './VehicleFields';
import React, { useState, useEffect, useRef } from 'react';
import { createRoot } from 'react-dom/client';
import {
  ProLayout,
  PageContainer,
  ProTable,
  type ProColumns,
  type ActionType,
} from '@ant-design/pro-components';
import {
  App as AntApp,
  ConfigProvider,
  Button,
  Select,
  Space,
  Tag,
  Card,
  Row,
  Col,
  Statistic,
  Form,
  Input,
  InputNumber,
  Switch,
  Drawer,
  Modal,
  Alert,
  Avatar,
  Checkbox,
  Tabs,
  Descriptions,
  Empty,
  Table,
  Typography,
} from 'antd';
import zhCN from 'antd/locale/zh_CN';
import {
  DashboardOutlined,
  CarOutlined,
  EnvironmentOutlined,
  TeamOutlined,
  ApartmentOutlined,
  UnorderedListOutlined,
  SettingOutlined,
  PlusOutlined,
  ArrowUpOutlined,
  LogoutOutlined,
  SafetyCertificateOutlined,
} from '@ant-design/icons';
import { api, setCsrf, money, states, type User } from './api';
import './style.css';

type RecordData = Record<string, any>;
const menus = [
  { path: '/overview', name: '运营总览', icon: <DashboardOutlined /> },
  { path: '/system_logs', name: '系统日志', icon: <UnorderedListOutlined /> },
  { path: '/orders', name: '订单与调度', icon: <UnorderedListOutlined /> },
  { path: '/companies', name: '运营企业', icon: <ApartmentOutlined /> },
  { path: '/depot_authorizations', name: '车厂授权', icon: <SafetyCertificateOutlined /> },
  { path: '/centers', name: '车辆中心', icon: <CarOutlined /> },
  { path: '/vehicles', name: '车型管理', icon: <CarOutlined /> },
  { path: '/points', name: '虚拟点与点组', icon: <EnvironmentOutlined /> },
  { path: '/bluemap', name: 'BlueMap 设置', icon: <EnvironmentOutlined /> },
  { path: '/scope', name: '运营范围', icon: <EnvironmentOutlined /> },
  { path: '/fare', name: '计费规则', icon: <SettingOutlined /> },
  { path: '/memberships', name: '成员与身份', icon: <TeamOutlined /> },
  { path: '/payments', name: '演示支付记录', icon: <SafetyCertificateOutlined /> },
  { path: '/audit_logs', name: '操作日志', icon: <SafetyCertificateOutlined /> },
];
const companyPages = ['/centers', '/vehicles', '/scope', '/fare', '/memberships'];
const logLabel = (labels: Record<string, string>) => (value: string | null) =>
  value ? (labels[value] ?? value) : '—';
const defaultFare = {
  mode: 'fixed',
  payment: 'after',
  baseMinor: 500,
  includedMeters: 2000,
  perKmMinor: 200,
  perMinuteMinor: 0,
  pickupFreeMeters: 3000,
  pickupPerKmMinor: 100,
  returnFreeMeters: 3000,
  returnPerKmMinor: 100,
  cancelMinor: 0,
  noShowMinor: 0,
};
const fareLabels: Record<string, string> = {
  baseMinor: '起步费（分）',
  includedMeters: '包含里程（米）',
  perKmMinor: '里程单价（分 / 千米）',
  perMinuteMinor: '时长单价（分 / 分钟）',
  pickupFreeMeters: '接客免费里程（米）',
  pickupPerKmMinor: '接客超程单价（分 / 千米）',
  returnFreeMeters: '回库免费里程（米）',
  returnPerKmMinor: '回库超程单价（分 / 千米）',
  cancelMinor: '派车后取消费（分）',
  noShowMinor: '无人上车取消费（分）',
};
const defaultVehicle = {
  maxSpeedKmh: 60,
  accelerationMps2: 1,
  decelerationMps2: 1,
  vehicleCars: [{ ...defaultCar }],
};
const parse = (s: unknown, fallback: any = {}) =>
  typeof s === 'string' ? JSON.parse(s) : s || fallback;

function AdminApp() {
  const { message, modal } = AntApp.useApp();
  const [user, setUser] = useState<User | null>(null),
    [demo, setDemo] = useState(false),
    [ready, setReady] = useState(false),
    [login, setLogin] = useState(false),
    [page, setPage] = useState(location.hash.slice(1) || '/overview'),
    [company, setCompany] = useState(''),
    [companies, setCompanies] = useState<RecordData[]>([]),
    [rows, setRows] = useState<RecordData[]>([]),
    [loading, setLoading] = useState(false),
    [edit, setEdit] = useState<RecordData | null>(null),
    [resource, setResource] = useState(''),
    [groups, setGroups] = useState<RecordData[]>([]),
    [points, setPoints] = useState<RecordData[]>([]),
    [catalog, setCatalog] = useState<Record<string, RecordData[]>>({}),
    [scope, setScope] = useState<string[]>([]),
    [scopeSelected, setScopeSelected] = useState<string[]>([]),
    [groupSelected, setGroupSelected] = useState<string[]>([]),
    [invite, setInvite] = useState(''),
    [busy, setBusy] = useState(false),
    [drawerOrder, setDrawerOrder] = useState<RecordData | null>(null),
    [orderEvents, setOrderEvents] = useState<RecordData[]>([]);
  const [depotRows, setDepotRows] = useState<RecordData[]>([]);
  const [systemLogs, setSystemLogs] = useState<RecordData[]>([]);
  const [systemLogError, setSystemLogError] = useState('');
  const [summary, setSummary] = useState({
    total: 0,
    active: 0,
    queued: 0,
    attention: 0,
    mod: 'checking',
  });
  const [form] = Form.useForm(),
    [fareForm] = Form.useForm();
  const table = useRef<ActionType>(undefined);
  const fail = (e: unknown) => message.error(e instanceof Error ? e.message : '请求失败');
  async function init() {
    try {
      const d = await api<{ user: User | null; demo: boolean }>('/me');
      setUser(d.user);
      setDemo(d.demo);
      if (d.user) setCsrf(d.user.csrf);
    } catch (e) {
      fail(e);
    } finally {
      setReady(true);
    }
  }
  useEffect(() => {
    init();
  }, []);
  useEffect(() => {
    if (!user) return;
    api<RecordData[]>('/admin/companies')
      .then((d) => {
        setCompanies(d);
        if (!company && d[0]) setCompany(d[0].id);
      })
      .catch(fail);
  }, [user]);
  async function load() {
    if (!user || (companyPages.includes(page) && !company)) return;
    setLoading(true);
    try {
      const r = page.slice(1);
      if (r === 'depot_authorizations') {
        setDepotRows(await api('/admin/catalog/depots'));
      } else if (r === 'system_logs') {
        setSystemLogs(await api('/admin/system_logs'));
        setSystemLogError('');
      } else if (r === 'overview' || r === 'orders') {
        setRows(await api('/admin/orders' + (company ? '?companyId=' + company : '')));
        setSummary(await api('/admin/summary' + (company ? '?companyId=' + company : '')));
      } else if (r === 'scope') {
        const [p, g, s] = await Promise.all([
          api('/admin/points'),
          api('/admin/point_groups'),
          api('/admin/companies/' + company + '/scope'),
        ]);
        setPoints(p);
        setGroups(g);
        setScope(s.pointIds);
      } else if (r === 'bluemap') {
        setRows([]);
      } else if (r === 'fare') {
        const c = companies.find((c) => c.id === company);
        fareForm.setFieldsValue(parse(c?.fare, defaultFare));
      } else {
        setRows(
          await api(
            '/admin/' +
              r +
              (['centers', 'vehicles', 'memberships'].includes(r) && company
                ? '?companyId=' + company
                : ''),
          ),
        );
        if (r === 'points') setGroups(await api('/admin/point_groups'));
      }
    } catch (e) {
      if (page === '/system_logs') {
        setSystemLogs([]);
        setSystemLogError(e instanceof Error ? e.message : '系统日志读取失败');
      } else {
        fail(e);
      }
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    load();
  }, [user, page, company, companies]);
  useEffect(() => {
    if (!user || !['/orders', '/overview', '/system_logs'].includes(page)) return;
    const t = setInterval(load, 7000);
    return () => clearInterval(t);
  }, [user, page, company]);
  async function demoLogin() {
    try {
      await api('/auth/demo', {});
      await init();
      setLogin(false);
    } catch (e) {
      fail(e);
    }
  }
  async function openEditor(r: string, row?: RecordData) {
    try {
      let values: RecordData = row ? { ...row } : { enabled: true };
      if (r === 'points') {
        const p = await api('/admin/catalog/platforms');
        setCatalog((v) => ({ ...v, platforms: p }));
        values = {
          ...values,
          groupId: row?.group_id,
          platformIds: row ? await api('/admin/points/' + row.id + '/platforms') : [],
        };
      }
      if (r === 'centers') {
        const [depots, sidings] = await Promise.all([
          api('/admin/catalog/depots'),
          api('/admin/catalog/sidings'),
        ]);
        setCatalog((v) => ({ ...v, depots, sidings }));
        values.depotPairs = parse(row?.depots, []).map((d: any) => d.depotId + '|' + d.sidingId);
      }
      if (r === 'vehicles') values.config = parse(row?.config, defaultVehicle);
      setResource(r);
      setEdit(row || {});
      form.resetFields();
      form.setFieldsValue(values);
    } catch (e) {
      fail(e);
    }
  }
  async function save() {
    try {
      const values = await form.validateFields();
      setBusy(true);
      if (resource === 'centers')
        values.depots = values.depotPairs.map((p: string) => {
          const [depotId, sidingId] = p.split('|');
          return { depotId, sidingId };
        });
      values.companyId = company;
      await api(
        '/admin/' + resource + (edit?.id ? '/' + edit.id : ''),
        values,
        edit?.id ? 'PATCH' : 'POST',
      );
      message.success('已保存');
      setEdit(null);
      if (resource === 'companies') {
        const updated = await api<RecordData[]>('/admin/companies');
        setCompanies(updated);
        setCompany((current) =>
          updated.some((item) => item.id === current) ? current : (updated[0]?.id ?? ''),
        );
      }
      await load();
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  async function saveScope(action: string) {
    setBusy(true);
    try {
      const d = await api('/admin/companies/' + company + '/scope', {
        action,
        pointIds: scopeSelected,
        groupIds: groupSelected,
      });
      setScope(d.map((v: any) => v.point_id));
      setScopeSelected([]);
      setGroupSelected([]);
      message.success('运营范围已更新');
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  async function saveFare() {
    try {
      const fare = await fareForm.validateFields();
      setBusy(true);
      await api('/admin/companies/' + company + '/fare', { fare });
      setCompanies(await api('/admin/companies'));
      message.success('计费规则已保存，新报价开始生效');
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  async function createInvite(role: string) {
    try {
      const d = await api('/admin/companies/' + company + '/invitations', { role });
      setInvite(d.url);
    } catch (e) {
      fail(e);
    }
  }
  async function inspect(o: RecordData) {
    setDrawerOrder(o);
    try {
      setOrderEvents((await api('/admin/orders/' + o.id + '/events')).items);
    } catch (e) {
      fail(e);
    }
  }
  async function control(action: string) {
    modal.confirm({
      title: '确认执行调度操作？',
      content:
        '操作将记录审计日志。有人乘坐时取消需要先下车；模组重启后已回收的订单将按失败关闭，费用归零并生成演示退款。',
      onOk: async () => {
        try {
          await api('/admin/orders/' + drawerOrder?.id + '/control', { action });
          message.success('指令已提交');
          load();
        } catch (e) {
          fail(e);
        }
      },
    });
  }
  const canWrite = Boolean(
    user?.platformAdmin ||
    user?.memberships.some((m) => m.company_id === company && m.role === 'admin'),
  );
  const resourceName: Record<string, string> = {
    companies: '运营企业',
    centers: '车辆中心',
    vehicles: '车型',
    points: '虚拟点',
    point_groups: '虚拟点组',
  };
  const columns: ProColumns<RecordData>[] =
    page === '/orders'
      ? [
          {
            title: '行程',
            key: 'route',
            render: (_, r) => (
              <div className="table-route">
                <strong>{r.pickup}</strong>
                <span>→ {r.dropoff}</span>
              </div>
            ),
          },
          {
            title: '订单状态',
            dataIndex: 'state',
            valueType: 'select',
            valueEnum: Object.fromEntries(
              Object.entries(states).map(([k, text]) => [
                k,
                {
                  text,
                  status:
                    k === 'ACTIVE'
                      ? 'Processing'
                      : k === 'COMPLETED'
                        ? 'Success'
                        : k === 'NEEDS_ATTENTION'
                          ? 'Error'
                          : 'Default',
                },
              ]),
            ),
          },
          { title: '乘客人数', dataIndex: 'passengers', search: false },
          {
            title: '金额',
            dataIndex: 'amountMinor',
            search: false,
            render: (_, r) => money(r.amountMinor),
          },
          {
            title: '创建时间',
            dataIndex: 'createdAt',
            search: false,
            render: (_, r) => new Date(r.createdAt * 1000).toLocaleString('zh-CN'),
          },
          {
            title: '操作',
            valueType: 'option',
            render: (_, r) => [
              <a key="detail" onClick={() => inspect(r)}>
                查看行程
              </a>,
            ],
          },
        ]
      : page === '/memberships'
        ? [
            { title: '成员', dataIndex: 'username' },
            {
              title: '身份',
              dataIndex: 'role',
              valueEnum: { admin: { text: '企业管理员' }, dispatcher: { text: '企业调度员' } },
            },
            {
              title: '操作',
              valueType: 'option',
              render: (_, r) =>
                user?.platformAdmin
                  ? [
                      <a
                        key="remove"
                        onClick={() =>
                          modal.confirm({
                            title: '移除此成员身份？',
                            onOk: async () => {
                              await api('/admin/memberships/' + r.id, {}, 'DELETE');
                              load();
                            },
                          })
                        }
                      >
                        移除身份
                      </a>,
                    ]
                  : [],
            },
          ]
        : page === '/payments'
          ? [
              { title: '订单', dataIndex: 'order_id' },
              {
                title: '类型',
                dataIndex: 'kind',
                valueEnum: { DEMO_PAY: { text: '演示付款' }, DEMO_REFUND: { text: '演示退款' } },
              },
              { title: '金额', dataIndex: 'amount', render: (_, r) => money(r.amount) },
              {
                title: '时间',
                dataIndex: 'created_at',
                render: (_, r) => new Date(r.created_at * 1000).toLocaleString('zh-CN'),
              },
            ]
          : page === '/audit_logs'
            ? [
                { title: '操作', dataIndex: 'action' },
                { title: '操作人', dataIndex: 'user_id' },
                { title: '对象', dataIndex: 'target' },
                {
                  title: '时间',
                  dataIndex: 'created_at',
                  render: (_, r) => new Date(r.created_at * 1000).toLocaleString('zh-CN'),
                },
              ]
            : [
                { title: '名称', dataIndex: 'name', render: (_, r) => <strong>{r.name}</strong> },
                ...(['/companies', '/centers', '/points'].includes(page)
                  ? [
                      {
                        title: '状态',
                        dataIndex: 'enabled',
                        search: false,
                        render: (_: unknown, r: RecordData) => (
                          <Tag color={r.enabled ? 'green' : 'default'}>
                            {r.enabled ? '运营中' : '已停用'}
                          </Tag>
                        ),
                      },
                    ]
                  : []),
                ...(page === '/centers'
                  ? [
                      {
                        title: '所属车厂',
                        search: false,
                        render: (_: unknown, r: RecordData) => (
                          <Space wrap>
                            {parse(r.depots, []).map((d: any) => (
                              <Tag key={d.depotId}>{d.depotId}</Tag>
                            ))}
                          </Space>
                        ),
                      },
                    ]
                  : []),
                ...(page === '/vehicles'
                  ? [{ title: '载客人数', dataIndex: 'capacity', search: false }]
                  : []),
                ...(page === '/points'
                  ? [
                      {
                        title: '所属点组',
                        search: false,
                        render: (_: unknown, r: RecordData) =>
                          groups.find((g) => g.id === r.group_id)?.name || '独立地点',
                      },
                      { title: '说明', dataIndex: 'description', search: false },
                    ]
                  : []),
                ...(page === '/companies'
                  ? [
                      {
                        title: '计费模式',
                        search: false,
                        render: (_: unknown, r: RecordData) =>
                          parse(r.fare).mode === 'fixed' ? '一口价' : '实时计费',
                      },
                    ]
                  : []),
                {
                  title: '操作',
                  valueType: 'option',
                  render: (_, r) =>
                    canWrite && (!['/companies', '/points'].includes(page) || user?.platformAdmin)
                      ? [
                          <a key="edit" onClick={() => openEditor(page.slice(1), r)}>
                            编辑
                          </a>,
                        ]
                      : [],
                },
              ];
  const { total, active, queued, attention, mod } = summary;
  if (!ready) return <div className="login-screen">正在连接运营平台…</div>;
  if (!user)
    return (
      <div className="login-screen">
        <div className="login-brand">↗ TAXILink</div>
        <div className="login-content">
          <span className="eyebrow">THE CITY, CONNECTED.</span>
          <h1>
            让城市流动，
            <br />
            让运营有序。
          </h1>
          <p>统一管理运营企业、车辆中心和每一段行程。</p>
          <Button type="primary" size="large" onClick={() => setLogin(true)}>
            登录运营管理平台 ↗
          </Button>
        </div>
        <LoginModal open={login} demo={demo} onCancel={() => setLogin(false)} onDemo={demoLogin} />
      </div>
    );
  if (!user.platformAdmin && !user.memberships.length)
    return (
      <div className="login-screen">
        <Empty description="当前账号尚无管理身份，请联系平台管理员邀请加入企业" />
        <Button
          onClick={async () => {
            await api('/auth/logout', {});
            init();
          }}
        >
          退出登录
        </Button>
      </div>
    );
  return (
    <ProLayout
      title="TAXILink"
      logo={<span className="admin-logo">↗</span>}
      layout="mix"
      siderWidth={224}
      fixSiderbar
      fixedHeader
      location={{ pathname: page }}
      route={{
        routes: menus.filter(
          (m) =>
            user.platformAdmin ||
            ![
              '/payments',
              '/audit_logs',
              '/system_logs',
              '/depot_authorizations',
              '/bluemap',
            ].includes(m.path),
        ),
      }}
      menuItemRender={(item, dom) => (
        <a
          href={`#${item.path}`}
          onClick={() => {
            setPage(item.path!);
            location.hash = item.path!;
          }}
        >
          {dom}
        </a>
      )}
      token={{
        header: { colorBgHeader: '#fff' },
        sider: {
          colorMenuBackground: '#fff',
          colorTextMenuSelected: '#276c51',
          colorBgMenuItemSelected: '#edf4ee',
        },
      }}
      avatarProps={{
        src: user.avatar || undefined,
        title: user.username,
        render: (_, dom) => (
          <Space>
            {dom}
            <Button
              type="text"
              icon={<LogoutOutlined />}
              aria-label="退出登录"
              onClick={async () => {
                await api('/auth/logout', {});
                setUser(null);
              }}
            />
          </Space>
        ),
      }}
      actionsRender={() => [
        <Tag key="demo" color="gold">
          支付仅演示{demo ? ' · 演示数据' : ''}
        </Tag>,
      ]}
    >
      <PageContainer
        title={menus.find((m) => m.path === page)?.name || '运营总览'}
        subTitle="连接每一次出发，掌握每一段行程。"
        extra={
          companies.length > 0 && ['/overview', '/orders', ...companyPages].includes(page)
            ? [
                <Select
                  key="company"
                  aria-label="当前企业"
                  placeholder="选择运营企业"
                  value={company || undefined}
                  onChange={setCompany}
                  options={companies.map((c) => ({ value: c.id, label: c.name }))}
                  style={{ minWidth: 200 }}
                />,
              ]
            : undefined
        }
      >
        {user.platformAdmin && companies.length === 0 && page === '/overview' && (
          <Alert
            style={{ marginBottom: 20 }}
            showIcon
            type="info"
            message="尚未创建运营企业"
            description="先创建企业，再配置车辆中心、车型、运营范围和计费规则。"
            action={<Button onClick={() => openEditor('companies')}>创建运营企业</Button>}
          />
        )}
        {companyPages.includes(page) && !company ? (
          <Card>
            <Empty description={companies.length ? '请先选择当前企业' : '请先创建运营企业'}>
              {user.platformAdmin && companies.length === 0 && (
                <Button type="primary" onClick={() => openEditor('companies')}>
                  创建运营企业
                </Button>
              )}
            </Empty>
          </Card>
        ) : page === '/depot_authorizations' ? (
          <Card
            title="专用车厂授权"
            extra={
              <Button loading={loading} onClick={load}>
                刷新
              </Button>
            }
          >
            <Typography.Paragraph>
              仅可授权拥有一条所属停车轨道的专用车厂。授权后可在车辆中心选用。
              {demo && '演示目录仅供查看。'}
            </Typography.Paragraph>
            <BlueMap points={depotRows.map(({ id, name, position }) => ({ id, name, position }))} />
            <Table<RecordData>
              rowKey="id"
              dataSource={depotRows}
              loading={loading}
              columns={[
                { title: '车厂', dataIndex: 'name' },
                { title: '车厂 ID', dataIndex: 'id' },
                { title: '停车轨道数', dataIndex: 'sidingCount' },
                {
                  title: '状态',
                  dataIndex: 'allowedForTaxi',
                  render: (allowed: boolean) => (
                    <Tag color={allowed ? 'green' : undefined}>{allowed ? '已授权' : '未授权'}</Tag>
                  ),
                },
                {
                  title: '操作',
                  render: (_, depot) => (
                    <Button
                      disabled={
                        demo ||
                        busy ||
                        !user.platformAdmin ||
                        (!depot.allowedForTaxi && depot.sidingCount !== 1)
                      }
                      danger={Boolean(depot.allowedForTaxi)}
                      onClick={() =>
                        modal.confirm({
                          title: depot.allowedForTaxi ? '撤销车厂授权？' : '授权车厂？',
                          content: depot.allowedForTaxi
                            ? '撤销后该车厂将不再参与新派车。若车辆中心仍引用它，请同步调整中心配置。'
                            : depot.name,
                          onOk: async () => {
                            setBusy(true);
                            try {
                              await api(
                                '/admin/depot-authorizations/' + encodeURIComponent(depot.id),
                                undefined,
                                depot.allowedForTaxi ? 'DELETE' : 'POST',
                              );
                              message.success(
                                depot.allowedForTaxi ? '已撤销车厂授权' : '已授权车厂',
                              );
                              await load();
                            } catch (error) {
                              fail(error);
                              throw error;
                            } finally {
                              setBusy(false);
                            }
                          },
                        })
                      }
                    >
                      {depot.allowedForTaxi ? '撤销授权' : '授权'}
                    </Button>
                  ),
                },
              ]}
            />
          </Card>
        ) : page === '/system_logs' ? (
          systemLogError ? (
            <Alert
              showIcon
              type="error"
              message="无法读取系统日志"
              description={systemLogError}
              action={
                <Button loading={loading} onClick={load}>
                  重试
                </Button>
              }
            />
          ) : (
            <Card
              title="WebSocket 系统日志"
              extra={
                <Button loading={loading} onClick={load}>
                  刷新
                </Button>
              }
            >
              <Table<RecordData>
                rowKey="id"
                dataSource={systemLogs}
                loading={loading}
                pagination={{ pageSize: 25 }}
                scroll={{ x: 960 }}
                columns={[
                  {
                    title: '时间',
                    dataIndex: 'created_at',
                    render: (_, row) =>
                      new Date(Number(row.created_at)).toLocaleString('zh-CN', {
                        fractionalSecondDigits: 3,
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                      }),
                  },
                  {
                    title: '通道',
                    dataIndex: 'channel',
                    render: logLabel({ mod: '模组', passenger: '乘客端' }),
                  },
                  {
                    title: '事件',
                    dataIndex: 'event',
                    render: logLabel({
                      connected: '连接成功',
                      connection_rejected: '连接拒绝',
                      disconnected: '断开连接',
                      message_rejected: '消息拒绝',
                      command_sent: '指令下发',
                      command_finished: '指令结束',
                    }),
                  },
                  { title: '连接 ID', dataIndex: 'connection_id' },
                  { title: '指令', dataIndex: 'command' },
                  {
                    title: '请求 ID',
                    dataIndex: 'request_id',
                    render: (value: string | null) =>
                      value ? <Typography.Text copyable>{value}</Typography.Text> : '—',
                  },
                  {
                    title: '总耗时',
                    dataIndex: 'duration_ms',
                    render: (_, row) => (row.duration_ms == null ? '—' : row.duration_ms + ' ms'),
                  },
                  {
                    title: '结果',
                    dataIndex: 'result',
                    render: logLabel({
                      success: '成功',
                      mod_error: '模组返回错误',
                      timeout: '超时',
                      send_failed: '下发失败',
                      disconnected: '连接断开',
                      handshake_invalid: '握手认证失败',
                      subscription_timeout: '订阅超时',
                      message_invalid: '消息验证失败',
                      origin_or_path: '来源或路径不匹配',
                      session_required: '会话无效',
                    }),
                  },
                ]}
              />
            </Card>
          )
        ) : page === '/overview' ? (
          <>
            <div className="overview-banner">
              <div>
                <span className="eyebrow">OPERATIONS AT A GLANCE</span>
                <h1>每一程，都井然有序。</h1>
                <p>
                  {companies.find((c) => c.id === company)?.name || '平台'} · 运营数据概览 ·{' '}
                  {(
                    {
                      connected: '模组已连接',
                      offline: '网关在线，模组未连接',
                      unavailable: '模组网关不可用',
                      demo: '模拟模组',
                      checking: '检查模组连接中',
                    } as Record<string, string>
                  )[mod] || '模组状态未知'}
                </p>
              </div>
              <div className="banner-orbit">
                <span>↗</span>
                <i />
                <i />
              </div>
            </div>
            <Row gutter={[20, 20]} className="stats-row">
              {[
                ['累计订单', total, '全部行程记录'],
                ['正在服务', active, '车辆与乘客连接中'],
                ['候补订单', queued, '等待可用车辆中心'],
                ['需要关注', attention, '等待调度员介入'],
              ].map(([label, value, note], i) => (
                <Col xs={12} xl={6} key={String(label)}>
                  <Card>
                    <span className="stat-icon">
                      {
                        [
                          <UnorderedListOutlined />,
                          <CarOutlined />,
                          <TeamOutlined />,
                          <SafetyCertificateOutlined />,
                        ][i]
                      }
                    </span>
                    <Statistic title={label} value={value ?? 0} />
                    <span className="stat-note">{note}</span>
                  </Card>
                </Col>
              ))}
            </Row>
            <Row gutter={[20, 20]}>
              <Col xs={24} xl={16}>
                <Card
                  title="最近行程"
                  extra={
                    <a
                      onClick={() => {
                        setPage('/orders');
                        location.hash = '/orders';
                      }}
                    >
                      查看全部 ↗
                    </a>
                  }
                >
                  <ProTable<RecordData>
                    rowKey="id"
                    search={false}
                    options={false}
                    toolBarRender={false}
                    pagination={{ pageSize: 5 }}
                    dataSource={rows.slice(0, 10)}
                    columns={[
                      { title: '上车点', dataIndex: 'pickup' },
                      { title: '下车点', dataIndex: 'dropoff' },
                      {
                        title: '状态',
                        dataIndex: 'state',
                        render: (_, r) => (
                          <Tag color={r.state === 'ACTIVE' ? 'green' : 'default'}>
                            {states[r.state]}
                          </Tag>
                        ),
                      },
                    ]}
                    loading={loading}
                  />
                </Card>
              </Col>
              <Col xs={24} xl={8}>
                <Card title="运营提示">
                  <div className="ops-note">
                    <span>01</span>
                    <div>
                      <strong>按轨道距离就近派车</strong>
                      <p>同一订单只有一个承运结果，其余候补自动撤销。</p>
                    </div>
                  </div>
                  <div className="ops-note">
                    <span>02</span>
                    <div>
                      <strong>费率在报价时留存</strong>
                      <p>调整计费规则后，新报价生效，已确认订单保留快照。</p>
                    </div>
                  </div>
                  <div className="ops-note">
                    <span>03</span>
                    <div>
                      <strong>身份随企业切换</strong>
                      <p>一个账号可以管理多个企业、拥有多个角色。</p>
                    </div>
                  </div>
                </Card>
              </Col>
            </Row>
          </>
        ) : page === '/bluemap' ? (
          user.platformAdmin ? (
            <BlueMapSettingsPage />
          ) : null
        ) : page === '/scope' ? (
          <Card title="运营范围白名单" extra={<Tag>上车点与下车点均需在范围内</Tag>}>
            <Alert
              type="info"
              showIcon
              message="批量选择点组时，仅添加或移除当前子点；后续新建子点需再次配置。"
              style={{ marginBottom: 24 }}
            />
            <Row gutter={32}>
              <Col xs={24} lg={14}>
                <Form layout="vertical">
                  <Form.Item label="批量选择点组">
                    <Select
                      mode="multiple"
                      value={groupSelected}
                      onChange={setGroupSelected}
                      options={groups.map((g) => ({ value: g.id, label: g.name }))}
                    />
                  </Form.Item>
                  <Form.Item label="选择具体虚拟点">
                    <Checkbox.Group
                      value={scopeSelected}
                      onChange={(v) => setScopeSelected(v as string[])}
                      className="scope-grid"
                    >
                      {points.map((p) => (
                        <Checkbox key={p.id} value={p.id}>
                          {p.name}
                        </Checkbox>
                      ))}
                    </Checkbox.Group>
                  </Form.Item>
                  <Space>
                    <Button
                      type="primary"
                      loading={busy}
                      disabled={!canWrite || !company}
                      onClick={() => saveScope('add')}
                    >
                      批量添加
                    </Button>
                    <Button
                      danger
                      loading={busy}
                      disabled={!canWrite || !company}
                      onClick={() => saveScope('remove')}
                    >
                      批量移除
                    </Button>
                  </Space>
                </Form>
              </Col>
              <Col xs={24} lg={10}>
                <div className="scope-preview">
                  <h3>当前可运营地点 · {scope.length}</h3>
                  <Space wrap>
                    {scope.map((id) => (
                      <Tag key={id} color="green">
                        {points.find((p) => p.id === id)?.name || id}
                      </Tag>
                    ))}
                  </Space>
                </div>
              </Col>
            </Row>
          </Card>
        ) : page === '/fare' ? (
          <Card title="企业统一计费规则">
            <Alert
              message="同一企业所有车辆中心使用此规则。金额以游戏币的分为单位，所有支付与退款仅演示。"
              type="info"
              showIcon
              style={{ marginBottom: 24 }}
            />
            <Form form={fareForm} layout="vertical" initialValues={defaultFare} onFinish={saveFare}>
              <Row gutter={24}>
                <Col xs={24} md={12}>
                  <Form.Item name="mode" label="计费模式">
                    <Select
                      options={[
                        { value: 'fixed', label: '一口价' },
                        { value: 'metered', label: '实时计费' },
                      ]}
                    />
                  </Form.Item>
                </Col>
                <Col xs={24} md={12}>
                  <Form.Item name="payment" label="付款模式">
                    <Select
                      options={[
                        { value: 'before', label: '先付后乘' },
                        { value: 'after', label: '先乘后付' },
                        { value: 'adjust', label: '预付后多退少补' },
                      ]}
                    />
                  </Form.Item>
                </Col>
                {Object.entries(fareLabels).map(([key, label]) => (
                  <Col xs={24} md={12} xl={8} key={key}>
                    <Form.Item name={key} label={label} rules={[{ required: true }]}>
                      <InputNumber
                        min={0}
                        max={100000000}
                        precision={0}
                        style={{ width: '100%' }}
                      />
                    </Form.Item>
                  </Col>
                ))}
              </Row>
              <Button
                type="primary"
                htmlType="submit"
                loading={busy}
                disabled={!canWrite || !company}
              >
                保存计费规则
              </Button>
            </Form>
          </Card>
        ) : (
          <>
            <ProTable<RecordData>
              actionRef={table}
              rowKey="id"
              columns={columns}
              dataSource={rows}
              loading={loading}
              search={false}
              headerTitle={menus.find((m) => m.path === page)?.name}
              pagination={{ pageSize: 10, showSizeChanger: true }}
              options={{ reload: () => load(), density: true, setting: true }}
              toolbar={{
                search: {
                  placeholder: '搜索名称或订单地点',
                  onSearch: (value) => {
                    if (!value) load();
                    else
                      setRows((v) =>
                        v.filter((r) =>
                          String(
                            r.name || r.username || r.pickup || r.order_id || r.action || '',
                          ).includes(value),
                        ),
                      );
                  },
                },
              }}
              toolBarRender={() => [
                ...(['/companies', '/centers', '/vehicles', '/points'].includes(page) &&
                canWrite &&
                (!['/companies', '/points'].includes(page) || user.platformAdmin)
                  ? [
                      <Button
                        key="add"
                        type="primary"
                        icon={<PlusOutlined />}
                        onClick={() => openEditor(page.slice(1))}
                      >
                        新增{resourceName[page.slice(1)]}
                      </Button>,
                    ]
                  : []),
                ...(page === '/points' && user.platformAdmin
                  ? [
                      <Button key="group" onClick={() => openEditor('point_groups')}>
                        管理点组
                      </Button>,
                    ]
                  : []),
                ...(page === '/memberships' && user.platformAdmin
                  ? [
                      <Button
                        key="invite-admin"
                        type="primary"
                        onClick={() => createInvite('admin')}
                      >
                        邀请管理员
                      </Button>,
                      <Button key="invite-dispatcher" onClick={() => createInvite('dispatcher')}>
                        邀请调度员
                      </Button>,
                    ]
                  : []),
              ]}
            />
            {page === '/points' && (
              <Card title="虚拟点组" style={{ marginTop: 20 }}>
                <Space wrap>
                  {groups.map((g) => (
                    <Button
                      key={g.id}
                      onClick={() => user.platformAdmin && openEditor('point_groups', g)}
                    >
                      {g.name}
                    </Button>
                  ))}
                </Space>
              </Card>
            )}
          </>
        )}
      </PageContainer>
      <Drawer
        title={`${edit?.id ? '编辑' : '新增'}${resourceName[resource] || ''}`}
        open={edit !== null}
        onClose={() => setEdit(null)}
        width={560}
        extra={
          <Button type="primary" loading={busy} onClick={save}>
            保存
          </Button>
        }
      >
        <Form form={form} layout="vertical">
          <Form.Item name="name" label="名称" rules={[{ required: true, max: 120 }]}>
            <Input />
          </Form.Item>
          {['companies', 'centers', 'points'].includes(resource) && (
            <Form.Item name="enabled" label="启用运营" valuePropName="checked">
              <Switch />
            </Form.Item>
          )}
          {resource === 'points' && (
            <>
              <Form.Item name="groupId" label="所属虚拟点组">
                <Select allowClear options={groups.map((g) => ({ value: g.id, label: g.name }))} />
              </Form.Item>
              <Form.Item name="description" label="地点说明">
                <Input />
              </Form.Item>
              {edit && (
                <BlueMap
                  points={(catalog.platforms || []).map((p) => ({
                    id: p.id,
                    name: [p.stationName, p.name].filter(Boolean).join(' / '),
                    position: p.position,
                  }))}
                  onSelect={(id) => {
                    const current: string[] = form.getFieldValue('platformIds') ?? [];
                    form.setFieldValue(
                      'platformIds',
                      current.includes(id)
                        ? current.filter((value) => value !== id)
                        : [...current, id],
                    );
                  }}
                />
              )}
              <Form.Item
                name="platformIds"
                label="关联站台轨道（支持多选）"
                rules={[{ required: true }]}
              >
                <Select
                  mode="multiple"
                  showSearch
                  optionFilterProp="label"
                  options={(catalog.platforms || []).map((p) => ({
                    value: p.id,
                    label: `${p.stationName || ''} ${p.name} · ${p.id}`,
                  }))}
                />
              </Form.Item>
            </>
          )}
          {resource === 'centers' && edit && (
            <BlueMap
              points={(catalog.sidings || []).flatMap((siding) => {
                const depot = catalog.depots?.find(
                  (d) => d.id === siding.depotId && d.allowedForTaxi && d.sidingCount === 1,
                );
                return depot
                  ? [
                      {
                        id: depot.id + '|' + siding.id,
                        name: depot.name + ' / ' + siding.name,
                        position: siding.position,
                      },
                    ]
                  : [];
              })}
              onSelect={(id) => {
                const current: string[] = form.getFieldValue('depotPairs') ?? [];
                form.setFieldValue(
                  'depotPairs',
                  current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
                );
              }}
            />
          )}
          {resource === 'centers' && (
            <Form.Item
              name="depotPairs"
              label="专用车厂及停车轨道"
              rules={[{ required: true }]}
              extra="每个车厂一条停车轨道，一个中心可包含多个车厂。平台管理员可在「车厂授权」页授权。"
            >
              <Select
                mode="multiple"
                options={(catalog.sidings || [])
                  .filter((s) =>
                    catalog.depots?.some(
                      (depot) =>
                        depot.id === s.depotId && depot.allowedForTaxi && depot.sidingCount === 1,
                    ),
                  )
                  .map((s) => ({
                    value: s.depotId + '|' + s.id,
                    label: `${catalog.depots?.find((d) => d.id === s.depotId)?.name || s.depotId} / ${s.id}`,
                  }))}
              />
            </Form.Item>
          )}
          {resource === 'vehicles' && <VehicleFields form={form} />}
        </Form>
      </Drawer>
      <Modal
        title="企业成员邀请"
        open={!!invite}
        onCancel={() => setInvite('')}
        footer={<Button onClick={() => setInvite('')}>完成</Button>}
      >
        <p>链接有效期为 7 天，仅可使用一次。请将链接交给受邀人，由其登录 Portal 后主动接受。</p>
        <Input.TextArea value={invite} readOnly rows={3} />
        <Button
          style={{ marginTop: 12 }}
          onClick={() =>
            navigator.clipboard.writeText(invite).then(() => message.success('已复制'))
          }
        >
          复制邀请链接
        </Button>
      </Modal>
      <Drawer
        title="订单与调度"
        width={650}
        open={!!drawerOrder}
        onClose={() => setDrawerOrder(null)}
      >
        {drawerOrder && (
          <>
            <Descriptions
              column={1}
              bordered
              items={[
                { key: 'id', label: '订单号', children: drawerOrder.id },
                {
                  key: 'route',
                  label: '路线',
                  children: drawerOrder.pickup + ' → ' + drawerOrder.dropoff,
                },
                { key: 'state', label: '状态', children: states[drawerOrder.state] },
                { key: 'amount', label: '金额', children: money(drawerOrder.amountMinor) },
              ]}
            />
            <BlueMap
              route={parse(drawerOrder.telemetry, {})?.geometry?.points}
              points={
                parse(drawerOrder.telemetry, {})?.position
                  ? [
                      {
                        id: 'vehicle',
                        name: '车辆位置',
                        position: parse(drawerOrder.telemetry, {}).position,
                      },
                    ]
                  : []
              }
            />
            <Space wrap style={{ margin: '20px 0' }}>
              <Button danger onClick={() => control('emergency')}>
                紧急停车
              </Button>
              <Button onClick={() => control('resume')}>恢复运行</Button>
              <Button onClick={() => control('cancel')}>取消／再次尝试取消</Button>
            </Space>
            <h3>订单动态</h3>
            {orderEvents.map((e, i) => (
              <div key={i} className="event-row">
                <Tag>{e.event_type}</Tag>
                <small>{new Date(e.created_at * 1000).toLocaleString('zh-CN')}</small>
              </div>
            ))}
          </>
        )}
      </Drawer>
    </ProLayout>
  );
}
function LoginModal({
  open,
  demo,
  onCancel,
  onDemo,
}: {
  open: boolean;
  demo: boolean;
  onCancel: () => void;
  onDemo: () => void;
}) {
  return (
    <Modal title="使用 HydCraft Portal 登录" open={open} onCancel={onCancel} footer={null}>
      <p>点击下方按钮前往 Portal 完成授权。只有获得平台或企业管理身份的账号可进入后台。</p>
      <Space direction="vertical" style={{ width: '100%', marginTop: 20 }}>
        <Button
          type="primary"
          block
          size="large"
          onClick={() => location.assign('/api/auth/start?destination=admin')}
        >
          前往 Portal 登录
        </Button>
        {demo && (
          <Button block size="large" onClick={onDemo}>
            体验演示管理员
          </Button>
        )}
      </Space>
    </Modal>
  );
}
createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <ConfigProvider
      locale={zhCN}
      theme={{
        token: {
          colorPrimary: '#286b51',
          borderRadius: 10,
          fontFamily: 'Inter, Segoe UI, Microsoft YaHei, sans-serif',
          colorBgLayout: '#f4f6f2',
        },
      }}
    >
      <AntApp>
        {location.pathname.replace(/\/$/, '') === '/install' ? <InstallPage /> : <AdminApp />}
      </AntApp>
    </ConfigProvider>
  </React.StrictMode>,
);
