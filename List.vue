<template>
    <div class="row">
        <create-modal
            :show="showLocationCreateModal"
            :referrer="'location'"
            :action_type="location_modal_action_type"
            :selected_location="location_to_edit"
            @locationModalUpdate="locationModalUpdate"
        ></create-modal>

        <div class="col-md-12 card">
            <div class="card-header">
                <div class="row">
                    <div class="col-10">
                        <h4>{{ $t('Location List') }}</h4>
                    </div>
                    <div class="col-2 text-right">
                        <button
                            type="button"
                            class="btn btn-icon btn-round btn-success btn-sm"
                            @click="showLocationCreateModal = true"
                        >
                            <i class="nc-icon nc-simple-add"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body row">
                <div class="col-sm-6 col-6">
                    <div class="category mb-2" style="font-size: .9em">{{ $t('Per page') }}</div>
                    <el-select class="select-default" v-model="pagination.perPage" :placeholder="$t('Per page')">
                        <el-option
                            class="select-default"
                            v-for="item in pagination.perPageOptions"
                            :key="item"
                            :label="item"
                            :value="item"
                        >
                        </el-option>
                    </el-select>
                </div>
                <div class="col-sm-6 col-6">
                    <div class="pull-right mt-4">
                        <fg-input
                            class="input-sm"
                            v-bind:placeholder="$t('Search')"
                            v-model="searchQuery"
                            addon-right-icon="nc-icon nc-zoom-split"
                        >
                        </fg-input>
                    </div>
                </div>
                <div class="col-sm-12 mt-2">
                    <el-table class="table-striped" :data="queriedData" border style="width: 100%">
                        <el-table-column
                            v-for="column in tableColumns"
                            :key="column.label"
                            :min-width="column.minWidth"
                            :prop="column.prop"
                            :label="$t(column.label)"
                            :formatter="dataFormatter"
                            :sortable="true"
                        >
                        </el-table-column>
                        <el-table-column :min-width="90" fixed="right" class-name="td-actions" :label="$t('Actions')">
                            <template slot-scope="props">
                                <template v-if="props.row.screens_count !== 0">
                                    <router-link
                                        class="btn-sm btn-block btn-icon btn-info text-center"
                                        :to="{ name: 'LocationsEdit', params: { id: props.row.id } }"
                                    >
                                        <i class="nc-icon nc-settings-gear-65"></i> {{ $t('view') }}
                                    </router-link>
                                </template>
                                <template v-else>
                                    <div class="text-center">
                                        <p-button
                                            type="success"
                                            size="sm"
                                            icon
                                            @click="editLocation(props.row.id)"
                                        >
                                            <i class="fa fa-edit"></i>
                                        </p-button>
                                        <p-button
                                            type="danger"
                                            size="sm"
                                            icon
                                            @click="handleDelete(props.$index, props.row)"
                                        >
                                            <i class="fa fa-trash"></i>
                                        </p-button>
                                    </div>
                                </template>
                            </template>
                        </el-table-column>
                    </el-table>
                </div>
                <div class="col-sm-6 mt-2 pagination-info">
                    <p class="category">
                        {{ $t('Showing') }} {{ from + 1 }} {{ $t('to') }} {{ to }} {{ $t('of') }} {{ total }}
                        {{ $t('entries') }}
                    </p>
                </div>
                <div class="col-sm-6 mt-2">
                    <p-pagination
                        class="pull-right"
                        v-model="pagination.currentPage"
                        :per-page="pagination.perPage"
                        :total="pagination.total"
                    >
                    </p-pagination>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
h4 {
    margin-top: 10px;
}
</style>

<script>
import Vue from 'vue';
import { mapState } from 'vuex';
import { Table, TableColumn, Select, Option } from 'element-ui';
import PPagination from 'src/components/UIComponents/Pagination.vue';
import CreateModal from '../Locations/CreateModal.vue';
import swal from 'sweetalert2';
import NProgress from 'nprogress';

Vue.use(Table);
Vue.use(TableColumn);
Vue.use(Select);
Vue.use(Option);

export default {
    name: 'LocationsList',
    components: {
        PPagination,
        CreateModal,
    },
    data() {
        return {
            pagination: {
                perPage: 25,
                currentPage: 1,
                perPageOptions: [5, 10, 25, 50],
                total: 0,
            },
            searchQuery: '',
            propsToSearch: ['address1', 'building_name', '["district"]["description"]', '["district"]["city"]["city"]'],
            tableColumns: [
                {
                    prop: 'screens_count',
                    label: 'No. Screens',
                },
                {
                    prop: 'address1',
                    label: 'Address',
                },
                {
                    prop: 'building_name',
                    label: 'Building Name',
                },
                {
                    prop: 'district.description',
                    label: 'District',
                },
                {
                    prop: 'district.city.city',
                    label: 'City',
                },
            ],
            showLocationCreateModal: false,
            location_modal_action_type: 'create',
            location_to_edit: false,
        };
    },
    computed: {
        ...mapState({
            tableData: state => state.locations.list,
        }),
        pagedData() {
            return this.tableData.slice(this.from, this.to);
        },
        queriedData() {
            if (!this.searchQuery) {
                this.pagination.total = this.tableData.length;
                return this.pagedData;
            }
            let result = this.tableData.filter(row => {
                let isIncluded = false;
                for (let key of this.propsToSearch) {
                    let rowValue = row[key];
                    if (typeof rowValue != 'undefined') {
                        rowValue = rowValue.toString();
                    } else {
                        rowValue = eval('row' + key);
                        rowValue = rowValue.toString();
                    }
                    if (rowValue.includes && rowValue.includes(this.searchQuery)) {
                        isIncluded = true;
                    }
                }
                return isIncluded;
            });
            this.pagination.total = result.length;
            return result.slice(this.from, this.to);
        },
        to() {
            let highBound = this.from + this.pagination.perPage;
            if (this.total < highBound) {
                highBound = this.total;
            }
            return highBound;
        },
        from() {
            return this.pagination.perPage * (this.pagination.currentPage - 1);
        },
        total() {
            this.pagination.total = this.tableData.length;
            return this.tableData.length;
        },
    },
    watch: {},
    methods: {
        locationModalUpdate(modal_status) {
            this.showLocationCreateModal  = modal_status;
            this.location_to_edit         = modal_status;
            this.location_modal_action_type = 'create';
        },
        editLocation(location_id) {
            this.showLocationCreateModal  = true;
            this.location_modal_action_type = 'edit';
            this.location_to_edit = location_id;
        },
        handleDelete(index, location) {
            swal({
                title: window.vueApp.$t('Are you sure?'),
                text: window.vueApp.$t('You want to delete this location'),
                type: 'warning',
                showCancelButton: true,
                confirmButtonClass: 'btn btn-success btn-fill',
                cancelButtonClass: 'btn btn-danger btn-fill',
                confirmButtonText: window.vueApp.$t('Yes, delete it!'),
                buttonsStyling: false,
            })
                .then(() => {
                    NProgress.start();
                    this.$store
                        .dispatch('locations/delete', location)
                        .then(() => {
                            this.$notify({
                                message: window.vueApp.$t('Location has been deleted successfully.'),
                                type: 'success',
                            });
                            NProgress.done();
                        })
                        .catch(() => {
                            this.$notify({
                                message: window.vueApp.$t(
                                    'Location could not be deleted at this time. Please refresh and try again.'
                                ),
                                type: 'danger',
                            });
                            NProgress.done();
                        });
                })
                .catch(() => {});
        },
        fetchLocations() {
            this.$store.dispatch('locations/fetch');
        },

        dataFormatter(row, column, cellValue, index) {
            if (column.label == 'Address') {
                let address = '-';
                if (row != null) {
                    let address1 = row.address1;
                    let address2 = row.address2;
                    if (address2 != null && address2 != '') {
                        address = address1 + ', ' + address2;
                    } else {
                        address = address1;
                    }
                }
                return address;
            } else if (column.label == 'District') {
                return row.district === null ? '-' : cellValue;
            } else if (column.label == 'City') {
                return row.district === null ? '-' : cellValue;
            } else if (column.label == 'Building Name') {
                return row.buildingName === null ? '-' : cellValue;
            } else {
                return cellValue;
            }
        },
    },
    created() {
        this.fetchLocations();
    },
};
</script>
