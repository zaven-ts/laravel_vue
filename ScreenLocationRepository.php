<?php

namespace App;

use App\Helpers\Translate;
use App\Http\Resources\ScreenLocation as ScreenLocationResource;
use App\Jobs\Translate as TranslateJob;
use App\Models\Screen;
use App\Models\ScreenLocation;
use App\Traits\HasCompanyType;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ScreenLocationRepository
{
    use HasCompanyType;

    /**
     * @var \App\Models\User Logged in user instance
     * @var int $user_id Logged in user id
     */
    private $user, $user_id;

    /** @var ScreenLocation Screen Location model instance */
    private $location_model;

    /** @var Translate $translate_helper Translate instance */
    private $translate_helper;

    public function __construct()
    {
        $this->user = (! empty(auth('api')->user())) ? auth('api')->user() : '';
        $this->user_id = (! empty($this->user->id)) ? $this->user->id : '';
        $this->location_model = new ScreenLocation;
        $this->translate_helper = new Translate;
    }

    /**
     * Fetch Locations list
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function getLocationsList()
    {
        $company_type = $this->getCompanyType();
        $user_company = $this->user->companies()->first()->id;

        $locations_list = ScreenLocation::when($company_type != 'Company Admin', function($query) use ($user_company) {
                $query->where('company_information_id', $user_company);
            })
            ->api_select()
            ->api_relations()
            ->orderBy('id', 'desc')
            ->get();

        return ScreenLocationResource::collection($locations_list);
    }

    /**
     * Fetch Location Details
     *
     * @param $id
     * @param bool $contacts
     * @return mixed
     */
    public function getLocationDetails($id, $contacts = false)
    {
        $company_type = $this->getCompanyType();
        $user_company = $this->user->companies()->first()->id;

        $location = ScreenLocation::when($company_type != 'Company Admin', function($query) use ($user_company) {
                $query->where('company_information_id', $user_company);
            })
            ->api_select()
            ->api_relations($contacts)
            ->where('id', $id)
            ->first();

        return $location;
    }

    /**
     * Store new location in storage
     *
     * @param Request $request
     * @return array
     */
    public function storeLocation(Request $request)
    {
        $request->validate($this->location_model->rules);

        $default_company_id = $this->user->companies()->first()->id;
        $company_type = $this->getCompanyType();

        $company_information_id = get_company_for_screen_properties(
            $default_company_id,
            $company_type,
            $request->company_information_id
        );

        if(is_array($company_information_id)) {
            return $company_information_id;
        }

        try {
            $location = new ScreenLocation;
            $location->users_id = $this->user_id;
            $location->company_information_id = $company_information_id;

            $location->setTranslations('address1', $this->translate_helper->set_without_translation($request->address1));
            $location->setTranslations('address2', $this->translate_helper->set_without_translation($request->address2));
            $location->setTranslations('building_name', $this->translate_helper->set_without_translation($request->building_name));
            $location->tags = ['en' => null, 'zh' => null];

            $location->location_sub_category_id = $request->subcategory;

            $location->geo_district = $request->district;
            $location->gps_location = $request->gps_location;

            if (! empty($request->contacts)) {
                $location->contacts()->createMany($request->contacts);
            }

            //Check if date range exists
            if (!empty($request->start_date) && !empty($request->end_date)) {
                //Create the JSON content for this field
                $location->contract_dates = [
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date
                ];
            }

            $location->save();

            // adding translation job into translation queue via database connection
            $columns_to_translate = [
                'address1',
                'address2',
                'building_name',
            ];
            TranslateJob::dispatch($location, $columns_to_translate, app()->getLocale())->onQueue('translations');

            $location->load(['district.city.province', 'subcategory.category']);
            $location->screens_count = 0;

            $response = [
                'location' => $location,
                'status'  => Response::HTTP_CREATED,
            ];
        } catch (\Exception $e) {
            $response = [
                'location' => [],
                'status'   => Response::HTTP_INTERNAL_SERVER_ERROR,
            ];
        }

        return $response;
    }

    /**
     * Update specific location properties.
     *
     * @param Request $request
     * @param int $location_id
     * @return bool|string|ScreenLocation $response
     */
    public function updateLocation($request, $location_id)
    {
        $request->validate($this->location_model->rules);
        $company_type = $this->getCompanyType();
        $user_companies = $this->user->companies()->pluck('id');
        $columns_to_translate = [];

        $location = ScreenLocation::
            when($company_type !== 'Company Admin', function ($query) use ($user_companies) {
                $query->whereIn('company_information_id', $user_companies);
            })
            ->api_relations()
            ->withCount('screens')
            ->findOrFail($location_id);

        try {
            if ($location->screens_count !== 0) {
                return 'screens count is bigger then 0';
            }

            $location->gps_location = $request->gps_location;
            $location->geo_district = $request->district;
            $location->location_sub_category_id = $request->subcategory;

            if ($location->address1 !== $request->address1) {
                $location->setTranslations('address1', $this->translate_helper->set_without_translation($request->address1));
                array_push($columns_to_translate, 'address1');
            }
            if ($location->address2 !== $request->address2) {
                $location->setTranslations('address2', $this->translate_helper->set_without_translation($request->address2));
                array_push($columns_to_translate, 'address2');
            }
            if ($location->building_name !== $request->building_name) {
                $location->setTranslations('building_name', $this->translate_helper->set_without_translation($request->building_name));
                array_push($columns_to_translate, 'building_name');
            }
            if (! empty($columns_to_translate)) {
                TranslateJob::
                    dispatch($location, $columns_to_translate, app()->getLocale())
                    ->onQueue('translations');
            }

            //Check if date range exists
            if (!empty($request->start_date) && !empty($request->end_date)) {
                //Create the JSON content for this field
                $location->contract_dates = [
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date
                ];
            }

            $location->save();
            $response = $location;
        } catch (\Exception $exception) {
            \Log::error('Screen location update error - ', ['error' => $exception]);
            $response = false;
        }

        return $response;
    }

    /**
     * Fetch screens list attached under specific location
     *
     * @param $id
     * @return mixed
     */
    public function getInstalledScreens($id)
    {
        $attached_screens = Screen::select(
                'id', 'alias', 'floor', 'status', 'screen_locations_id',
                'installation_description', 'building_number'
            )
            ->where('screen_locations_id', $id)
            ->get();

        return $attached_screens;
    }

    /**
     * Update specific location tags
     *
     * @param $location_id
     * @param Request $request
     * @return bool
     */
    public function updateLocationTags($location_id, $request)
    {
        $request->validate([
            'tags'        => 'required|array',
            'tags.*.text' => 'string',
        ]);
        $tags = $request->input('tags.*.text');
        $tags = implode(',', $tags);

        try {
            $company = null;

            $companyType = $this->getCompanyType();

            if ($companyType != 'Company Admin') {
                $company = $this->user->companies()->first()->id;
            }

            $location = new ScreenLocation;
            if (! is_null($company)) {
                $location = $location->where('company_information_id', $company);
            }
            $location = $location->findOrFail($location_id);

            $location->setTranslations('tags', $this->translate_helper->set_without_translation($tags));
            $location->save();

            // adding translation job into translation queue via database connection
            TranslateJob::dispatch($location, ['tags'], app()->getLocale())
                ->onQueue('translations');

            $response = true;
        } catch (\Exception $e) {
            $response = false;
        }

        return $response;
    }
}
