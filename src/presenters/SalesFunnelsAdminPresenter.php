<?php

namespace Crm\SalesFunnelModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Components\Graphs\GoogleBarGraphGroupControlFactoryInterface;
use Crm\ApplicationModule\Components\Graphs\GoogleLineGraphGroupControlFactoryInterface;
use Crm\ApplicationModule\Components\VisualPaginator;
use Crm\ApplicationModule\ExcelFactory;
use Crm\ApplicationModule\Graphs\Criteria;
use Crm\ApplicationModule\Graphs\GraphDataItem;
use Crm\PaymentsModule\Components\LastPaymentsControlFactoryInterface;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SalesFunnelModule\Components\WindowPreviewControlFactoryInterface;
use Crm\SalesFunnelModule\Forms\SalesFunnelAdminFormFactory;
use Crm\SalesFunnelModule\Repository\SalesFunnelsMetaRepository;
use Crm\SalesFunnelModule\Repository\SalesFunnelsPaymentGatewaysRepository;
use Crm\SalesFunnelModule\Repository\SalesFunnelsRepository;
use Crm\SalesFunnelModule\Repository\SalesFunnelsStatsRepository;
use Crm\SalesFunnelModule\Repository\SalesFunnelsSubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Nette\Application\Responses\CallbackResponse;
use Crm\SubscriptionsModule\Subscription\SubscriptionTypeHelper;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use Tomaj\Form\Renderer\BootstrapRenderer;

class SalesFunnelsAdminPresenter extends AdminPresenter
{
    private $salesFunnelsRepository;

    private $salesFunnelAdminFormFactory;

    private $salesFunnelsMetaRepository;

    private $salesFunnelsStatsRepository;

    private $paymentGatewaysRepository;

    private $paymentsRepository;

    private $subscriptionTypesRepository;

    private $salesFunnelsSubscriptionTypesRepository;

    private $salesFunnelsPaymentGatewaysRepository;

    private $excelFactory;

    private $subscriptionTypeHelper;

    public function __construct(
        SalesFunnelsRepository $salesFunnelsRepository,
        SalesFunnelAdminFormFactory $salesFunnelAdminFormFactory,
        SalesFunnelsMetaRepository $salesFunnelsMetaRepository,
        SalesFunnelsStatsRepository $salesFunnelsStatsRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        PaymentsRepository $paymentsRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        SalesFunnelsSubscriptionTypesRepository $salesFunnelsSubscriptionTypesRepository,
        SalesFunnelsPaymentGatewaysRepository $salesFunnelsPaymentGatewaysRepository,
        ExcelFactory $excelFactory,
        SubscriptionTypeHelper $subscriptionTypeHelper
    ) {
        parent::__construct();
        $this->salesFunnelsRepository = $salesFunnelsRepository;
        $this->salesFunnelAdminFormFactory = $salesFunnelAdminFormFactory;
        $this->salesFunnelsMetaRepository = $salesFunnelsMetaRepository;
        $this->salesFunnelsStatsRepository = $salesFunnelsStatsRepository;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->salesFunnelsSubscriptionTypesRepository = $salesFunnelsSubscriptionTypesRepository;
        $this->salesFunnelsPaymentGatewaysRepository = $salesFunnelsPaymentGatewaysRepository;
        $this->excelFactory = $excelFactory;
        $this->subscriptionTypeHelper = $subscriptionTypeHelper;
    }

    /**
     * @admin-access-level read
     */
    public function renderDefault()
    {
        $this->template->funnels = $this->salesFunnelsRepository->all();
    }

    /**
     * @admin-access-level write
     */
    public function renderNew()
    {
    }

    /**
     * @admin-access-level read
     */
    public function renderShow($id)
    {
        $funnel = $this->salesFunnelsRepository->find($id);
        if (!$funnel) {
            $this->flashMessage($this->translator->translate('sales_funnel.admin.sales_funnels.messages.sales_funnel_not_found'), 'danger');
            $this->redirect('default');
        }
        $this->template->funnel = $funnel;
        $this->template->funnelSubscriptionTypes = $this->salesFunnelsRepository->getSalesFunnelSubscriptionTypes($funnel);
        $this->template->funnelGateways = $this->salesFunnelsRepository->getSalesFunnelGateways($funnel);

        $this->template->total_paid_amount = $this->salesFunnelsRepository->totalPaidAmount($funnel);
        $this->template->subscriptionTypesPaymentsMap = $this->salesFunnelsRepository->getSalesFunnelDistribution($funnel);
        $this->template->meta = $this->salesFunnelsMetaRepository->all($funnel);

        $payments = $this->paymentsRepository->getTable()
            ->where(['status' => PaymentsRepository::STATUS_PAID, 'sales_funnel_id' => $funnel->id])
            ->order('paid_at DESC');

        $filteredCount = $this->template->filteredCount = $payments->count('*');
        $vp = new VisualPaginator();
        $this->addComponent($vp, 'paymentsvp');
        $paginator = $vp->getPaginator();
        $paginator->setItemCount($filteredCount);
        $paginator->setItemsPerPage($this->onPage);

        $this->template->vp = $vp;
        $this->template->payments = $payments->limit($paginator->getLength(), $paginator->getOffset());
    }

    /**
     * @admin-access-level read
     */
    public function renderPreview($id)
    {
        $funnel = $this->salesFunnelsRepository->find($id);
        $this->template->funnel = $funnel;
    }

    /**
     * @admin-access-level write
     */
    public function renderEdit($id)
    {
        $this->template->funnel = $this->salesFunnelsRepository->find($id);
    }

    public function createComponentSalesFunnelForm()
    {
        $id = null;
        if (isset($this->params['id'])) {
            $id = intval($this->params['id']);
        }

        $form = $this->salesFunnelAdminFormFactory->create($id);

        $this->salesFunnelAdminFormFactory->onSave = function ($funnel) {
            $this->flashMessage($this->translator->translate('sales_funnel.admin.sales_funnels.messages.funnel_created'));
            $this->redirect('show', $funnel->id);
        };
        $this->salesFunnelAdminFormFactory->onUpdate = function ($funnel) {
            $this->flashMessage($this->translator->translate('sales_funnel.admin.sales_funnels.messages.funnel_updated'));
            $this->redirect('show', $funnel->id);
        };
        return $form;
    }

    protected function createComponentPaymentGatewayForm()
    {
        $form = new Form();
        $form->setRenderer(new BootstrapRenderer());
        $form->addProtection();
        $form->setTranslator($this->translator);
        $form->getElementPrototype()->addAttributes(['class' => 'ajax']);

        $funnel = $this->salesFunnelsRepository->find($this->params['id']);
        $unavailableIds = array_keys($funnel->related('sales_funnels_payment_gateways')->fetchPairs('payment_gateway_id'));
        $where = [];
        if ($unavailableIds) {
            $where['id NOT IN ?'] = $unavailableIds;
        }

        $paymentGateway = $form->addSelect('payment_gateway_id', 'subscriptions.data.subscription_types.fields.name', $this->paymentGatewaysRepository->all()->where($where)->fetchPairs('id', 'name'))
            ->setRequired('subscriptions.data.subscription_types.required.name');
        $paymentGateway->getControlPrototype()->addAttributes(['class' => 'select2']);

        $form->addSubmit('send', 'system.save')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-save"></i> ' . $this->translator->translate('system.save'));

        $form->onSuccess[] = function ($form, $values) use ($funnel) {
            $this->salesFunnelsPaymentGatewaysRepository->add(
                $funnel,
                $this->paymentGatewaysRepository->find($values->payment_gateway_id)
            );
            if ($this->isAjax()) {
                $this->redrawControl('paymentGatewayForm');
            } else {
                $this->redirect('show', $funnel->id);
            }
        };

        return $form;
    }

    /**
     * @admin-access-level write
     */
    public function handleRemovePaymentGateway($paymentGatewayId)
    {
        $funnel = $this->salesFunnelsRepository->find($this->params['id']);
        $funnel->related('sales_funnels_payment_gateways')->where([
            'payment_gateway_id' => $paymentGatewayId,
        ])->delete();
        if ($this->isAjax()) {
            $this->redrawControl('paymentGatewayForm');
        } else {
            $this->redirect('show', $funnel->id);
        }
    }
    /**
     * @admin-access-level write
     */
    public function handleMovePaymentGatewayUp($salesFunnelId, $paymentGatewayId)
    {
        $this->movePaymentGateway('up', $salesFunnelId, $paymentGatewayId);
        if ($this->isAjax()) {
            $this->redrawControl('paymentGatewayForm');
        } else {
            $this->redirect('show', $salesFunnelId);
        }
    }

    /**
     * @admin-access-level write
     */
    public function handleMovePaymentGatewayDown($salesFunnelId, $paymentGatewayId)
    {
        $this->movePaymentGateway('down', $salesFunnelId, $paymentGatewayId);
        if ($this->isAjax()) {
            $this->redrawControl('paymentGatewayForm');
        } else {
            $this->redirect('show', $salesFunnelId);
        }
    }

    private function movePaymentGateway(string $where, int $salesFunnelId, int $paymentGatewayId): void
    {
        $salesFunnel = $this->salesFunnelsRepository->find($salesFunnelId);

        $pairs = $this->salesFunnelsPaymentGatewaysRepository->findAllBySalesFunnel($salesFunnel);
        $pairs = array_values($pairs);
        foreach ($pairs as $i => $pair) {
            if ($where === 'up') {
                $swapI = $i-1;
            } elseif ($where === 'down') {
                $swapI = $i+1;
            } else {
                break;
            }

            if ($pair->payment_gateway_id == $paymentGatewayId && array_key_exists($swapI, $pairs)) {
                $swap = $pairs[$swapI];
                $swapSorting = $swap->sorting;
                $pairSorting = $pair->sorting;
                $this->salesFunnelsPaymentGatewaysRepository->update($pair, [
                    'sorting' => $swapSorting
                ]);
                $this->salesFunnelsPaymentGatewaysRepository->update($swap, [
                    'sorting' => $pairSorting
                ]);
                break;
            }
        }
    }

    protected function createComponentSubscriptionTypeForm()
    {
        $form = new Form();
        $form->setRenderer(new BootstrapRenderer());
        $form->addProtection();
        $form->setTranslator($this->translator);
        $form->getElementPrototype()->addAttributes(['class' => 'ajax']);

        $funnel = $this->salesFunnelsRepository->find($this->params['id']);
        $unavailableIds = array_keys($funnel->related('sales_funnels_subscription_types')->fetchPairs('subscription_type_id'));
        $where = [];
        if ($unavailableIds) {
            $where['id NOT IN ?'] = $unavailableIds;
        }

        // zmen nazvy
        $subscriptionTypes = $this->subscriptionTypeHelper->getPairs($this->subscriptionTypesRepository->all()->where($where), true);
        $subscriptionType = $form->addSelect('subscription_type_id', 'subscriptions.data.subscription_types.fields.name', $subscriptionTypes)
            ->setRequired('subscriptions.data.subscription_types.required.name');
        $subscriptionType->getControlPrototype()->addAttributes(['class' => 'select2']);

        $form->addSubmit('send', 'system.save')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-save"></i> ' . $this->translator->translate('system.save'));

        $form->onSuccess[] = function ($form, $values) use ($funnel) {
            $this->salesFunnelsSubscriptionTypesRepository->add(
                $funnel,
                $this->subscriptionTypesRepository->find($values->subscription_type_id)
            );
            if ($this->isAjax()) {
                $this->redrawControl('subscriptionTypesForm');
            } else {
                $this->redirect('show', $funnel->id);
            }
        };

        return $form;
    }

    /**
     * @admin-access-level write
     */
    public function handleRemoveSubscriptionType($subscriptionTypeId)
    {
        $funnel = $this->salesFunnelsRepository->find($this->params['id']);
        $funnel->related('sales_funnels_subscription_types')->where([
            'subscription_type_id' => $subscriptionTypeId,
        ])->delete();
        if ($this->isAjax()) {
            $this->redrawControl('subscriptionTypesForm');
        } else {
            $this->redirect('show', $funnel->id);
        }
    }

    /**
     * @admin-access-level write
     */
    public function handleMoveSubscriptionTypeUp($salesFunnelId, $subscriptionTypeId)
    {
        $this->moveSubscriptionType('up', $salesFunnelId, $subscriptionTypeId);
        if ($this->isAjax()) {
            $this->redrawControl('subscriptionTypesForm');
        } else {
            $this->redirect('show', $salesFunnelId);
        }
    }

    /**
     * @admin-access-level write
     */
    public function handleMoveSubscriptionTypeDown($salesFunnelId, $subscriptionTypeId)
    {
        $this->moveSubscriptionType('down', $salesFunnelId, $subscriptionTypeId);
        if ($this->isAjax()) {
            $this->redrawControl('subscriptionTypesForm');
        } else {
            $this->redirect('show', $salesFunnelId);
        }
    }

    /**
     * @admin-access-level read
     */
    public function handleExportUsersWithPayment($salesFunnelId)
    {
        $excelSpreadSheet = $this->excelFactory->createExcel('Sales funnel payments - ' . $salesFunnelId);
        $funnel = $this->salesFunnelsRepository->find($salesFunnelId);

        $lastId = 0;
        $step = 1000;
        $paidPayments = $this->paymentsRepository->getTable()
            ->where([
                'payments.sales_funnel_id' => $funnel->id,
                'payments.status' => PaymentsRepository::STATUS_PAID,
            ]);
        $rows = [];

        while (true) {
            $results = $paidPayments
                ->select('payments.*, user.email')
                ->where('payments.id > ?', $lastId)
                ->order('payments.id ASC')
                ->limit($step)
                ->fetchAll();

            foreach ($results as $row) {
                $rows[] =[
                    $row->email,
                    $row->paid_at,
                    $row->amount
                ];
                $lastId = $row->id;
            }

            if (count($results) < $step) {
                break;
            }
        }

        $excelSpreadSheet->getActiveSheet()->fromArray($rows);

        $writer = new Csv($excelSpreadSheet);
        $writer->setDelimiter(';');
        $writer->setUseBOM(true);
        $writer->setEnclosure('"');

        $now = new DateTime();
        $fileName = 'sales-funnel-' . $salesFunnelId . '-payments-export-' . $now->format('Y-m-d') . '.csv';
        $this->getHttpResponse()->addHeader('Content-Encoding', 'windows-1250');
        $this->getHttpResponse()->addHeader('Content-Type', 'application/octet-stream; charset=windows-1250');
        $this->getHttpResponse()->addHeader('Content-Disposition', "attachment; filename=" . $fileName);

        $response = new CallbackResponse(function () use ($writer) {
            $writer->save("php://output");
        });

        $this->sendResponse($response);
    }

    private function moveSubscriptionType(string $where, int $salesFunnelId, int $subscriptionTypeId): void
    {
        $salesFunnel = $this->salesFunnelsRepository->find($salesFunnelId);

        $pairs = $this->salesFunnelsSubscriptionTypesRepository->findAllBySalesFunnel($salesFunnel);
        $pairs = array_values($pairs);
        foreach ($pairs as $i => $pair) {
            if ($where === 'up') {
                $swapI = $i-1;
            } elseif ($where === 'down') {
                $swapI = $i+1;
            } else {
                break;
            }

            if ($pair->subscription_type_id == $subscriptionTypeId && array_key_exists($swapI, $pairs)) {
                $swap = $pairs[$swapI];
                $swapSorting = $swap->sorting;
                $pairSorting = $pair->sorting;
                $this->salesFunnelsSubscriptionTypesRepository->update($pair, [
                    'sorting' => $swapSorting
                ]);
                $this->salesFunnelsSubscriptionTypesRepository->update($swap, [
                    'sorting' => $pairSorting
                ]);
                break;
            }
        }
    }

    protected function createComponentFunnelShowGraph(GoogleLineGraphGroupControlFactoryInterface $factory)
    {
        $graphDataItem = new GraphDataItem();
        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('sales_funnels_stats')
            ->setTimeField('date')
            ->setWhere('AND sales_funnel_id=' . intval($this->params['id']) . ' AND type=\'' . SalesFunnelsStatsRepository::TYPE_SHOW . '\'')
            ->setValueField('SUM(value)')
            ->setStart('-1 month'))
            ->setName('Show');

        return $factory->create()
            ->setGraphTitle($this->translator->translate('sales_funnel.admin.sales_funnels.show.graph_show_stats.title'))
            ->setGraphHelp($this->translator->translate('sales_funnel.admin.sales_funnels.show.graph_show_stats.help'))
            ->addGraphDataItem($graphDataItem);
    }

    protected function createComponentFunnelGraph(GoogleLineGraphGroupControlFactoryInterface $factory)
    {
        $graph = $factory->create()
            ->setGraphTitle($this->translator->translate('sales_funnel.admin.sales_funnels.show.graph_funnel_stats.title'))
            ->setGraphHelp($this->translator->translate('sales_funnel.admin.sales_funnels.show.graph_funnel_stats.help'));

        $types = $this->salesFunnelsStatsRepository->getTable()
            ->select('type')
            ->where(['sales_funnel_id' => $this->params['id']])
            ->group('type')
            ->fetchAll();

        /** @var ActiveRow $row */
        foreach ($types as $row) {
            $graphDataItem = new GraphDataItem();
            $graphDataItem->setCriteria((new Criteria())
                ->setTableName('sales_funnels_stats')
                ->setTimeField('date')
                ->setWhere('AND sales_funnel_id=' . intval($this->params['id']) . ' AND type=\'' . $row->type . '\'')
                ->setValueField('SUM(value)')
                ->setStart('-1 month'))
                ->setName($row->type);

            $graph->addGraphDataItem($graphDataItem);
        }

        return $graph;
    }

    protected function createComponentFunnelConversionRateGraph(GoogleLineGraphGroupControlFactoryInterface $factory)
    {
        $graph = $factory->create()
            ->setGraphTitle($this->translator->translate('sales_funnel.admin.sales_funnels.show.graph_conversion_rate_stats.title'))
            ->setGraphHelp($this->translator->translate('sales_funnel.admin.sales_funnels.show.graph_conversion_rate_stats.help'));

        $deviceTypes = $this->salesFunnelsStatsRepository->getTable()
            ->select('device_type')
            ->where(['sales_funnel_id' => $this->params['id']])
            ->group('device_type')
            ->fetchAll();

        $salesFunnelId = (int) $this->params['id'];

        /** @var ActiveRow $row */
        foreach ($deviceTypes as $row) {
            $graphDataItem = new GraphDataItem();
            $graphDataItem->setCriteria((new Criteria())
                ->setTableName('sales_funnels_stats')
                ->setTimeField('date')
                ->setWhere("AND sales_funnel_id={$salesFunnelId} AND device_type='{$row->device_type}' AND type='ok'")
                ->setValueField(" (SUM(value) / (SELECT SUM(value) FROM sales_funnels_stats WHERE type='show' AND device_type='{$row->device_type}' AND sales_funnel_id={$salesFunnelId} AND DATE(sales_funnels_stats.`date`) = calendar.`date`)) * 100 ")
                ->setStart('-1 month'))
                ->setName($row->device_type);
            $graph->addGraphDataItem($graphDataItem);
        }

        $graphDataItem = new GraphDataItem();
        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('sales_funnels_stats')
            ->setTimeField('date')
            ->setWhere("AND sales_funnel_id={$salesFunnelId} AND type='ok'")
            ->setValueField(" (SUM(value) / (SELECT SUM(value) FROM sales_funnels_stats WHERE type='show' AND sales_funnel_id={$salesFunnelId} AND DATE(sales_funnels_stats.`date`) = calendar.`date`)) * 100 ")
            ->setStart('-1 month'))
            ->setName($this->translator->translate('sales_funnel.admin.component.sales_funnel_stats_by_device.all_devices'));

        $graph->addGraphDataItem($graphDataItem);

        return $graph;
    }

    protected function createComponentPaymentGatewaysGraph(GoogleBarGraphGroupControlFactoryInterface $factory)
    {
        $graphDataItem = new GraphDataItem();
        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setTimeField('modified_at')
            ->setWhere("AND payments.status = 'paid' AND payments.sales_funnel_id=" . intval($this->params['id']))
            ->setGroupBy('payment_gateways.name')
            ->setJoin('LEFT JOIN payment_gateways on payment_gateways.id = payments.payment_gateway_id')
            ->setSeries('payment_gateways.name')
            ->setValueField('count(*)')
            ->setStart((new DateTime())->modify('-1 month')->format('Y-m-d'))
            ->setEnd((new DateTime())->format('Y-m-d')));

        $control = $factory->create();
        $control->setGraphTitle($this->translator->translate('dashboard.payments.gateways.title'))
            ->setGraphHelp($this->translator->translate('dashboard.payments.gateways.tooltip'))
            ->addGraphDataItem($graphDataItem);

        return $control;
    }

    protected function createComponentSubscriptionsGraph(GoogleBarGraphGroupControlFactoryInterface $factory)
    {
        $graphDataItem = new GraphDataItem();

        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setGroupBy('payment_items.name')
            ->setJoin(
                "LEFT JOIN payment_items ON payment_id = payments.id"
            )
            ->setWhere('AND payments.sales_funnel_id=' . intval($this->params['id']) . ' AND payments.status=\'paid\'')
            ->setSeries('payment_items.name')
            ->setValueField('sum(payment_items.count)')
            ->setStart((new DateTime())->modify('-1 month')->format('Y-m-d'))
            ->setEnd((new DateTime())->format('Y-m-d')));

        $control = $factory->create();
        $control->setGraphTitle($this->translator->translate('sales_funnel.admin.component.subscriptions_graph.title'))
            ->setGraphHelp($this->translator->translate('sales_funnel.admin.component.subscriptions_graph.help'))
            ->addGraphDataItem($graphDataItem);

        return $control;
    }

    public function createComponentLastPayments(LastPaymentsControlFactoryInterface $factory)
    {
        $control = $factory->create();
        $control->setSalesFunnelId($this->params['id'])
            ->setLimit(5);
        return $control;
    }

    public function createComponentWindowPreview(WindowPreviewControlFactoryInterface $factory)
    {
        $control = $factory->create();
        $control->setSalesFunnelId($this->params['id']);
        return $control;
    }
}
