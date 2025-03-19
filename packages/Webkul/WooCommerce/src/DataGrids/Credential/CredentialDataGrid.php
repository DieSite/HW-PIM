<?php

namespace Webkul\WooCommerce\DataGrids\Credential;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class CredentialDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('wk_woocommerce_credentials')
            ->select(
                'id',
                'shopUrl',
                'consumerKey',
                'consumerSecret',
                'active',
                'defaultSet',
            );

        return $queryBuilder;
    }

    /**
     * Add columns.
     *
     * @return void
     */
    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'shopUrl',
            'label'      => trans('woocommerce::app.woocommerce.credential.datagrid.shopUrl'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'active',
            'label'      => trans('woocommerce::app.woocommerce.credential.datagrid.active'),
            'type'       => 'boolean',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
            'closure'    => fn ($row) => $row->active ? '<span class="label-active">'.trans('admin::app.common.yes').'</span>' : '<span class="label-info text-gray-600 dark:text-gray-300">'.trans('admin::app.common.no').'</span>',
        ]);

        $this->addColumn([
            'index'      => 'defaultSet',
            'label'      => trans('woocommerce::app.woocommerce.credential.datagrid.defaultSet'),
            'type'       => 'boolean',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
            'closure'    => fn ($row) => $row->defaultSet ? '<span class="label-active">'.trans('admin::app.common.yes').'</span>' : '<span class="label-info text-gray-600 dark:text-gray-300">'.trans('admin::app.common.no').'</span>',
        ]);
    }

    /**
     * Prepare actions.
     *
     * @return void
     */
    public function prepareActions()
    {
        if (bouncer()->hasPermission('woocommerce.credentials.edit')) {
            $this->addAction([
                'icon'   => 'icon-edit',
                'title'  => trans('admin::app.catalog.attributes.index.datagrid.edit'),
                'method' => 'GET',
                'url'    => function ($row) {
                    return route('woocommerce.credentials.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('woocommerce.credentials.delete')) {
            $this->addAction([
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.catalog.attributes.index.datagrid.delete'),
                'method' => 'DELETE',
                'url'    => function ($row) {
                    return route('woocommerce.credentials.delete', $row->id);
                },
            ]);
        }
    }
}
