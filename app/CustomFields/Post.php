<?php

namespace CroissantMSNFeedPlugin\CustomFields;

class Post {

    /**
     * Registers the custom field 'publish_to_msn' for the post types declared in the locations array.
     * This field is used to determine whether a post should be included in the RSS feed which is generated in accordance with MSN requirements.
     *
     * @return void
     * @since 1.0.0
     * @access public
     *
     * @uses acf_add_local_field_group()
     * @see https://www.advancedcustomfields.com/resources/acf_add_local_field_group/
     */
    public function register_publish_to_msn_field() {

        if ( function_exists( 'acf_add_local_field_group' ) ) {
            $key = 'publish_to_msn';
            acf_add_local_field_group(
                [
                    'key'        => $key . '_group',
                    'title'      => 'MSN News',
                    'fields'     => [
                        [
                            'key'           => $key,
                            'label'         => 'Publish this post to MSN',
                            'name'          => $key,
                            'type'          => 'true_false',
                            'default_value' => true,
                            'instructions'  => 'Publish this story to the MSN news',
                        ]
                    ],
                    'location'   => [
                        [
                            [
                                'param'    => 'post_type',
                                'operator' => '==',
                                'value'    => 'post',
                            ],
                        ]
                    ],
                    'menu_order' => 11,
                    'position'   => 'side',
                ]
            );
        }
    }
}
