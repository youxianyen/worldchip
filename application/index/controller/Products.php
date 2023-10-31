<?php

namespace app\index\controller;

use app\common\controller\Frontend;
use think\Request;
use app\common\model\Product;
use app\common\model\Company;
use app\common\model\CompanyProduct;
use app\common\model\ProductRelations;
use app\common\model\ProductAttributes;
use app\common\model\ProductCategory;
use think\Db;
use think\Cache;
use Redis;

class Products extends Frontend
{

    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = '';

    public function index()
    {
        $request = Request::instance();
        $cid = $request->param('cid', 141);
        $page = $request->param('p', 1);
        $perPage = $request->param('perPage', 20);

        $category = ProductCategory::where('id', $cid)->find();

        $products = Product::with('relations')
            ->where('category_id', $cid)
            ->paginate($perPage);


        $total = $products->total(); // 获取总记录数
        $nextpage = $page + 1; // 计算下一页的页码

        if ($total == 0) {
            $products = Product::with('relations')
                ->where('category_id', 14)
                ->paginate($perPage);
            $total = $products->total(); // 获取总记录数
        }

        // 检查下一页是否超出页数范围
        if ($nextpage > ceil($total / $perPage)) {
            $nextpage = ceil($total / $perPage);
        }

        // 处理产品图片
        foreach ($products as $product) {
            if ($product['image'] == 'https://media.digikey.com/photos/nophoto/pna_en.jpg') {
                $product['image'] = '/static/picture/wd.png';
            }
            // 提取image字段并替换域名
            $product['image'] = str_replace('//media.digikey.com', 'https://image.chips.selleroa.top', $product['image']);  
            $product['image'] = str_replace('https:https://', 'https://', $product['image']);  
            $product['datasheet'] = str_replace('https:http://', 'https://', $product['datasheet']);  

            $original_url = $product['image'];
            $base_directory = '/home/imgs/img_fold';

            // 替换域名
            $relative_path = str_replace('//media.digikey.com', $base_directory, $original_url);

            // 将相对路径转换为绝对路径
            $absolute_path = realpath(($relative_path));

            if ($relative_path !== false && file_exists($relative_path) && strpos($product['image'], 'digikey.com') !== false) {

                // echo "文件存在：$absolute_path" . 'relative_path-' . $relative_path . '-absolute_path-' . $absolute_path;
            } else {
                // echo "文件不存在：$absolute_path" . 'relative_path-' . $relative_path . '-absolute_path-' . $absolute_path;
                // 假设已经建立了 Redis 连接


                    $product['image'] = str_replace('https://mm.digikey.com', 'https://image.chips.selleroa.top', $product['image']);
                    if (isset($product['img'])) {
                        $product['img'] = str_replace('https://mm.digikey.com', 'https://image.chips.selleroa.top', $product['img']);
                    }
                    $jsonarr = ["_id" => $product['url'], 'img' => $product['image']];
                    $redis = new Redis();
                    $redis->connect('127.0.0.1', 6379);
                    $redis->auth('zb07dvq8d4Yf3SKz4Vlai3Nq');  // 在这里设置 Redis 密码
                    $redisKey = 'digi_img_url';
                    // 删除之前存储的键值
                    $redis->del('digi_img_url');
                    // 将数据插入 Redis 列表
                    $result = $redis->lPush('digi_img_url', json_encode($jsonarr));
                     // 读取 Redis 列表中的所有元素
                    $list = $redis->lRange('digi_img_url', 0, -1);
                    
                    // 设置缓存过期时间，假设为1小时
                    $redis->expire($redisKey, 3600 * 24 * 7);

            }

        }

        $productsarray = $products->toArray();

        $this->assign('products', $products);
        $this->assign('cid', $cid);
        $this->assign('category', $category);
        $this->assign('productsarray', $productsarray);
        $this->assign('page', $page);
        $this->assign('nextpage', $nextpage);

        return $this->view->fetch();
    }


    public function viewModel(Request $request)
    {
        $cid = $request->param('cid', 141);
        $page = $request->param('p', 1);
        $perPage = $request->param('perPage', 20);
        $attributeModel = new ProductAttributes();
        $attributes = $attributeModel->getDistinctAttributes(['name' => 'Mfr']);
        $Manufacturer = $attributes;
        $Package = $attributeModel->getDistinctAttributes(['name' => 'Package']);
        $Series = $attributeModel->getDistinctAttributes(['name' => 'Series']);
        $productStatus = $attributeModel->getDistinctAttributes(['name' => 'Product Status']);        

        // 检查请求方法是否为 POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // 从请求主体中获取 JSON 数据
            // 使用$request对象一次性获取所有POST参数
            // 获取POST请求数据
            $postData = $request->post();
            $page = $request->param('p', 1);

            // 提取特征名称和选项数据
            $featureNames = $postData['featureNames'];
            $selectedOptionsByFeature = $postData['selectedOptionsByFeature'];
            $stockingOptionsSelectedValues = $postData['stockingOptionsSelectedValues'];
            $environmentalOptionsSelectedValues = $postData['environmentalOptionsSelectedValues'];
            $mediaOptionsSelectedValues = $postData['mediaOptionsSelectedValues'];
            $marketplaceProductOptionsSelectedValues = $postData['marketplaceProductOptionsSelectedValues'];

            // 创建主查询
            $query = Db::name('cms_product_attribute_definition');

            // 创建条件查询
            $conditionQuery = Db::name('cms_product_attribute_definition');

            // 初始化一个空的查询条件数组
            $conditions = [];
            
            // 根据特征和选项构建查询条件
            foreach ($featureNames as $feature) {
                if (!empty($selectedOptionsByFeature[$feature])) {
                    // 创建特征名称的查询条件
                    $columnMapping = [
                        'Packaging' => 'Package',
                        'Manufacturer' => 'mfr',
                    ];

                    $columnName = isset($columnMapping[$feature]) ? $columnMapping[$feature] : $feature;
                    $conditions[] = "`name` = '$columnName'";

                    // 创建选项的查询条件
                    $options = implode("', '", $selectedOptionsByFeature[$feature]);
                    $conditions[] = "`description` IN ('$options')";
                }
            }

            // if (!empty($stockingOptionsSelectedValues[0]) && $stockingOptionsSelectedValues[0] === 'In Stock') {
            //     $conditions[] = "`stock` > 0";
            // }

            if (!empty($environmentalOptionsSelectedValues[0])) {
                if ($environmentalOptionsSelectedValues[0] === 'RoHS Compliant') {
                    $conditions[] = "name = 'RoHS Status' and description = 'ROHS3 Compliant'";
                }

                if ($environmentalOptionsSelectedValues[0] === 'Non-RoHS Compliant') {
                    $conditions[] = "name = 'RoHS Status' and description != 'ROHS3 Compliant'";
                }
            }


            $sql = "SELECT DISTINCT `product_id` FROM `fa_cms_product_attribute_definition`";

            if ($conditions) {
                $sql .= " WHERE " . implode(" OR ", $conditions);
            }
            

            // 执行查询
            $productIds = Db::query($sql);
            $actualProductIds = [];
            foreach ($productIds as $item) {
                $actualProductIds[] = $item['product_id'];
            }
            // 打印结果
            
            

            // 使用产品ID查询产品信息
            $products = Db::name('cms_product')
                ->where('id', 'in', $actualProductIds)
                ->paginate($perPage);

            foreach ($products as $product) {
                if ($product['image'] == 'https://media.digikey.com/photos/nophoto/pna_en.jpg') {
                    $product['image'] = '/static/picture/wd.png';
                }
            }

            
            self::jsonEncode('200', 'success', $products);

            // 返回 JSON 响应
            return json(['status' => 'success', 'data' => $products, 'page' => $page]);
        }

        // 如果不是 POST 请求，则继续原来的逻辑
        $request = Request::instance();
        

        // 构建查询
        $query = Product::with('relations')->where('category_id', $cid);

        // 添加其他筛选条件示例
        // $query->where(...);

        // 执行查询
        $products = $query->paginate($perPage);
        $arr = $products->toArray();

        if ($arr['total'] == 0) {
            // 如果没有匹配的数据，可以执行备用查询
            $products = Product::with('relations')
                ->where('category_id', 14)
                ->paginate($perPage);
        }

        foreach ($products as $product) {
            if ($product['image'] == 'https://media.digikey.com/photos/nophoto/pna_en.jpg') {
                $product['image'] = '/static/picture/wd.png';
            }
        }

        $this->assign('products', $products);
        $this->assign('page', $page);
        $this->assign('attributes', $attributes);
        $this->assign('Manufacturer', $Manufacturer);
        $this->assign('Series', $Series);
        $this->assign('Package', $Package);
        $this->assign('productStatus', $productStatus);

        return $this->view->fetch();
    }

    public function searchProduct(Request $request){
        $data = input();
        $page = $request->param('p', 1);
        // 初始化查询构建器
        $query = Db::name('cms_product');
        $conditions[] = ['name', '=', 'Package'];
        $perPage = 100;

        // 根据用户提供的产品名构建模糊搜索条件
        if (!empty($data['name'])) {
            $product_name = $data['name'];
            $query->where('name', 'like', "%$product_name%");
        }
        // 执行查询，获取匹配的产品列表
        $products = $query->paginate($perPage);
        return json(['status' => 'success', 'data' => $products, 'page' => $page]);
    }

    public function filterProduct(Request $request)
    {
        $cid = $request->param('cid', 141);
        $page = $request->param('p', 1);
        $perPage = $request->param('perPage', 20);
        $attributeModel = new ProductAttributes();
        $attributes = $attributeModel->getDistinctAttributes(['name' => 'Mfr']);
        $Manufacturer = $attributes;
        $Package = $attributeModel->getDistinctAttributes(['name' => 'Package']);
        $Series = $attributeModel->getDistinctAttributes(['name' => 'Series']);
        $productStatus = $attributeModel->getDistinctAttributes(['name' => 'Product Status']);

        // 提取筛选逻辑为独立方法
        $products = $this->applyFilters($request, $perPage);

        $this->assign('Manufacturer', $Manufacturer);
        $this->assign('page', $page);
        $this->assign('Package', $Package);
        $this->assign('Series', $Series);
        $this->assign('productStatus', $productStatus);
        $this->assign('products', $products);

        // 返回视图，显示筛选后的产品
        return $this->view->fetch();
    }

    // 提取的筛选逻辑方法
    private function applyFilters(Request $request, $perPage)
    {
        // 获取前端发送的条件
        $postData = $request->post();
        $filters = $postData;
        // 初始化查询构建器
        $query = Db::name('cms_product_attribute_definition');

        // 根据特征名称和选项构建查询条件
        // 初始化一个空的查询条件数组
        $conditions = [];

        // 根据筛选条件构建查询条件
        foreach ($filters as $filter) {
            $type = $filter['type'];
            $description = $filter['description'];

            // 根据不同的筛选条件类型构建相应的查询条件
            switch ($type) {
                case 'Category':
                    // 添加对产品分类的筛选条件                        
                    break;
                case 'Mfr':
                    // 添加对制造商的筛选条件
                    $conditions[] = ['name', '=', 'Mfr'];
                    $conditions[] = ['description', '=', $description];
                    break;
                case 'Series':
                    // 添加对系列的筛选条件
                    $conditions[] = ['name', '=', 'Series'];
                    $conditions[] = ['description', '=', $description];
                    break;
                case 'Package':
                    // 添加对包装的筛选条件
                    $conditions[] = ['name', '=', 'Package'];
                    $conditions[] = ['description', '=', $description];
                    break;
                case 'ProductStatus':
                    // 添加对产品状态的筛选条件
                    $conditions[] = ['name', '=', 'Product Status'];
                    $conditions[] = ['description', '=', $description];
                    break;
                // 可以根据需要继续添加其他筛选条件的处理逻辑
                default:
                    break;
            }
        }

        // 循环遍历$conditions数组，并构建查询条件
        foreach ($conditions as $condition) {
            list($columnName, $operator, $value) = $condition;

            // 根据特征名称、运算符和描述构建查询条件
            $query->where($columnName, $operator, $value);
        }

        // 执行查询，获取匹配的产品 ID 列表
        $productIds = $query->column('DISTINCT product_id');

        // 使用产品 ID 列表来查询 product 表
        $productQuery = Db::table('cms_product')->whereIn('id', $productIds);
        $filteredProducts = $productQuery->paginate($perPage);
        
        return $filteredProducts;
    }


    public function jsonEncode($code,$message='',$data=array()){
        if(!is_numeric($code)){
            return '';
        }
        //把要返回给前端的数据组合 
        $result=array(
            'code'=>$code,
            'message'=>$message,
            'data'=>$data
            );
        //array => json
        echo json_encode($result);
        exit;
        
    }

    public function dikiy() 
    {
        
        return $this->view->fetch();
    }

    public function worksarrays() 
    {
        $request = Request::instance();
        
        return $this->view->fetch();
    }

    public function productdetail()
    {
        $request = Request::instance();
        $id = $request->param('id');
        
        if ($id) {
            $productModel = new Product();
            $ProductRelation = new ProductRelations();
            $product = Product::getinfo($id);
            if (empty($product)) {
                return $this->error(__('找不到相关信息'), '/');
            }

            if ($product) {
                $product = $product->toArray();
                $unitPrice = (float)$ProductRelation->where('id', $id)->where('qty', 1)->value('unit_price');
                $extPrice = (float)$ProductRelation->where('id', $id)->where('qty', 1)->value('ext_price');
                $extPrice = number_format($extPrice, 2);
                $mfrDescription = $productModel->getAttributeValueByField($id, 'Mfr');
                $seriesDescription = $productModel->getAttributeValueByField($id, 'Series');
                $PackageDescription = $productModel->getAttributeValueByField($id, 'Package');
                $ProductStatusDescription = $productModel->getAttributeValueByField($id, 'Product Status');
                $TypeDescription = $productModel->getAttributeValueByField($id, 'Type');
                $TipMaterialDescription = $productModel->getAttributeValueByField($id, 'Tip Material');
                $HandleMaterialDescription = $productModel->getAttributeValueByField($id, 'Handle Material');
                $FeaturesDescription = $productModel->getAttributeValueByField($id, 'Features');
                $HandleLengthDescription = $productModel->getAttributeValueByField($id, 'Handle Length');
                $QuantityDescription = $productModel->getAttributeValueByField($id, 'Quantity');
                $datasheetDescription = $productModel->getAttributeValueByField($id, 'Datasheet');
                
                if ($product['image'] == 'https://media.digikey.com/photos/nophoto/pna_en.jpg') 
                {
                    $product['image'] = 'https://www.theworldchips.com/static/picture/wd.png';
                }

                $link = $product['url'];
                $url = "https://fast.chips.selleroa.top/?id=" . $link;
                $curldata = file_get_contents($url);
                $curldata2arr = json_decode($curldata, true);

                $original_url = $curldata2arr['data']['img'];
                $base_directory = '/home/imgs/img_fold';
                // 解析URL，并获取所有路径信息
                $url_parts = parse_url($original_url);
                $path = $url_parts['path'];

                // 获取最后一个文件名
                $file_name = basename($path);
                // echo 'file_name-' . $file_name;exit;
                // 替换域名
                $relative_path = str_replace('//media.digikey.com', $base_directory, $original_url);

                // 将相对路径转换为绝对路径
                $absolute_path = realpath(($relative_path));
                $relative_path = str_replace('https:', '', $relative_path);
                $escaped_path = escapeshellarg($relative_path);
                // 获取文件名
                $ofile_name = basename($absolute_path);
                $original_file = $relative_path;
                // 获取目录路径
                $target_directory = dirname($original_file);

                // 解码文件名
                $decoded_file_name = urldecode($file_name);

                // 新的目标路径
                $new_file_path = $target_directory . $decoded_file_name;

                if ($relative_path !== false && file_exists($relative_path)) {
//                     echo "<br/>filename-$file_name-文件存在1：$absolute_path" . '<br/>relative_path-' . $relative_path . '<br/>-absolute_path-' . $absolute_path . '-ofile_name-' . $ofile_name . '-$original_file' . $original_file . '-$target_directory-' . $target_directory;


                    // 获取文件名
                    // 解析文件名和路径
                    $file_name = basename($relative_path);
                    $directory_path = dirname($relative_path);
                    // 解码路径
                    $decoded_directory_path = urldecode($directory_path);
                    // 创建目标目录
                    if (!is_dir($target_directory)) {
                        mkdir($target_directory, 0777, true);
                    }


                    // 复制文件
                    if (!file_exists($new_file_path)) {
                        if (copy($relative_path, $new_file_path)) {
//                            echo '文件复制成功-' . $decoded_file_name;
                        } else {
//                            echo '文件复制失败-' . $decoded_file_name;
                        }
                    } else {
//                        echo '文件已存在-' . $decoded_file_name;
                    }

                    
                    // echo 1;
                } else {
//                     echo "<br/>filename-$file_name-文件不存在4：$absolute_path" . '<br/>relative_path-' . $relative_path . '<br/>-absolute_path-' . $absolute_path . '-original_url-' . $original_url;
                    // echo 2;
                    // 假设已经建立了 Redis 连接
                    $jsonarr = ["_id" => $product['url'], 'img' => $original_url];

                    $redis = new Redis();
                    $redis->connect('127.0.0.1', 6379);
                    $redis->auth('zb07dvq8d4Yf3SKz4Vlai3Nq');  // 在这里设置 Redis 密码
                    $redisKey = 'digi_img_url';
                    // 删除之前存储的键值
                    $redis->del('digi_img_url');
                    // 将数据插入 Redis 列表
                    $result = $redis->lPush('digi_img_url', json_encode($jsonarr));
                     // 读取 Redis 列表中的所有元素
                    $list = $redis->lRange('digi_img_url', 0, -1);
                    // 设置缓存过期时间，假设为1小时
                    $redis->expire($redisKey, 3600 * 24 * 7);


                }
                

                // $product['datasheet'] = $datasheetDescription;
                // $product['mfr'] = $mfrDescription;
                // $product['series'] = $seriesDescription;
                // $product['package'] = $PackageDescription;
                // $product['productstatus'] = $ProductStatusDescription;
                // $product['type'] = $TypeDescription;
                // $product['tipmaterial'] = $TipMaterialDescription;
                // $product['handlematerial'] = $HandleMaterialDescription;
                // $product['features'] = $FeaturesDescription;
                // $product['quantity'] = $QuantityDescription;
                // $product['handlelength'] = $HandleLengthDescription;
                // $product['stock'] = mt_rand(30, 12000);
                // Define the pattern to match the redundant "https:http:"
                $pattern = '/^https:http:/i';

                // Remove the redundant "https:http:" from the link
                $modifiedLink = preg_replace($pattern, 'http:', $product['datasheet']);
                $product['datasheet'] = $modifiedLink;
                $datasheetlink1 = $product['datasheetlink1'];
                $newlink = $this->splitLink($datasheetlink1);
                $product['datasheet'] = $newlink[0];
                $othername = $product['othername1'];
                $othernamearr = explode('&', $othername);

                $curldata2arr = [];
                // if (empty($product['tip_material'])) {
                //     $url = "https://sync.chips.selleroa.top/updateProductdetail.php?id=" . $id;
                //     $curldata = file_get_contents($url);
                //     $curldata2arr = json_decode($curldata, true);
                    
                // }
                $categoryId = $product['category_id'];
                
                $filteredData = [
                    'Mfr' => !empty($curldata2arr['Mfr']) ? $curldata2arr['Mfr'] : '',
                    'datasheetsname1' => !empty($product['datasheetsname1']) ? $product['datasheetsname1'] : '',
                    'datasheetlink1' => !empty($product['datasheetlink1']) ? $product['datasheetlink1'] : '',
                    'rohs_status' => !empty($product['rohs_status']) ? $product['rohs_status'] : '',
                    'msl' => !empty($product['msl']) ? $product['msl'] : '',
                    'reach_status' => !empty($product['reach_status']) ? $product['reach_status'] : '',
                    'eccn' => !empty($product['eccn']) ? $product['eccn'] : '',
                    'htsus' => !empty($product['htsus']) ? $product['htsus'] : '',
                    'othername1' => !empty($product['othername1']) ? $product['othername1'] : '',
                    'standard_package' => !empty($product['standard_package']) ? $product['standard_package'] : '',
                    'stock' => !empty($product['stock']) ? $product['stock'] : '',
                    'series' => !empty($product['series']) ? $product['series'] : '',
                    'Package' => !empty($product['Package']) ? $product['Package'] : '',
                    'Product Status' => !empty($product['Product Status']) ? $product['Product Status'] : '',
                    'Container Type' => !empty($product['Container Type']) ? $product['Container Type'] : '',
                    'Size / Dimension' => !empty($product['Size / Dimension']) ? $product['Size / Dimension'] : '',
                    'Size / Dimension' => !empty($product['Size / Dimension']) ? $product['Size / Dimension'] : '',
                    'Height' => !empty($product['Height']) ? $product['Height'] : '',
                    'Area (L x W)' => !empty($product['Area (L x W)']) ? $product['Area (L x W)'] : '',
                    'Design' => !empty($product['Design']) ? $product['Design'] : '',
                    'Material' => !empty($product['Material']) ? $product['Material'] : '',
                    'Color' => !empty($product['Color']) ? $product['Color'] : '',
                    'Thickness' => !empty($product['Thickness']) ? $product['Thickness'] : '',
                    'Features' => !empty($product['Features']) ? $product['Features'] : '',
                    'Ratings' => !empty($product['Ratings']) ? $product['Ratings'] : '',
                    'Material Flammability Rating' => !empty($product['Material Flammability Rating']) ? $product['Material Flammability Rating'] : '',
                    'Shipping Info' => !empty($product['Shipping Info']) ? $product['Shipping Info'] : '',
                    'Weight' => !empty($product['Weight']) ? $product['Weight'] : '',
                    'Base Product Number' => !empty($product['Base Product Number']) ? $product['Base Product Number'] : '',
                    'tip_material' => !empty($product['tip_material']) ? $product['tip_material'] : '',
                    'handle_material' => !empty($product['handle_material']) ? $product['handle_material'] : '',
                    'rohs_status' => !empty($product['rohs_status']) ? $product['rohs_status'] : '',
                ];
                $temp = [];
                $categories = $this->getCategoryNamesWithId($categoryId);
                // 提取image字段并替换域名
                $product['image'] = str_replace('//media.digikey.com', 'https://image.chips.selleroa.top', $product['image']);
                // 替换 URL
                $product['image'] = str_replace("https:https://", "https://", $product['image']);

                $url = $product['image'];
                $base_directory = '/home/imgs/img_fold';

                $parsed_url = parse_url($url); // 解析 URL

                $path = $parsed_url['path']; // 获取路径部分
                $relative_path = ltrim($path, '/'); // 去掉开头的斜杠
                $absolute_path = $base_directory . '/' . rawurldecode($relative_path);
                $absolute_path1 = $base_directory . '/' . $relative_path;
                $product['image'] = 'https://image.chips.selleroa.top' . '/' . rawurldecode($relative_path);

                // 创建文件夹（如果不存在）
                $directory = dirname($absolute_path);

                if (!is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }

                // 获取文件名
                $file_name = basename($relative_path);
                // 剪切文件到目标路径
                $new_file_path = $directory . '/' . $file_name;
                // 判断文件是否存在
                if (file_exists($absolute_path1)) {
                    // 移动文件到目标路径
                    rename($absolute_path1, $new_file_path);
                    // echo "文件移动成功，新路径为：" . $new_file_path;
                } else {
//                     echo "$absolute_path 目录文件不存在.<br/>base_directory-" . $base_directory . '<br/>-relative_path-' . $relative_path . '<br/> . absolute_path1-' . $absolute_path1 . '<br/>-new_file_path-' . $new_file_path . '$original_url-' . $original_url;
//                    $product['image'] = 'https://image.chips.selleroa.top' . '/' . $relative_path . '-$file_name-' . $file_name . '-$ofile_name' . $file_name . '-ofilename-' . $ofile_name;

                    // 假设已经建立了 Redis 连接
                    $jsonarr = ["_id" => $product['url'], 'img' => $url];
                    
                    $redis = new Redis();
                    $redis->connect('127.0.0.1', 6379);
                    $redis->auth('zb07dvq8d4Yf3SKz4Vlai3Nq');  // 在这里设置 Redis 密码
                    $redisKey = 'digi_img_url';
                    
                    // 将数据插入 Redis 列表
                    $result = $redis->lPush($redisKey, json_encode($jsonarr));
                    
                    // 设置缓存过期时间，假设为1小时
                    $redis->expire($redisKey, 3600 * 24 * 7);
                }

                $this->assign('categories', $categories);
                $this->assign('filteredData', $filteredData);
                
                $this->assign('product', $product);

                $this->assign('curldata2arr', $curldata2arr);
                $this->assign('othername', $othernamearr);
                $this->assign('unitPrice', $unitPrice);
                $this->assign('extPrice', $extPrice);
                // echo "environmentlink: ";
                
                return $this->fetch('productdetail'); // 渲染视图
            }
        } else {
            // 返回 403 Forbidden 状态码
            http_response_code(404);            
            die;
        }
        return $this->view->fetch('productdetail');
    }

    private function splitLink($link) {
        if (strpos($link, '@$') !== false) {
            $links = explode('@$', $link);
            return $links;
        } else {
            return [$link];
        }
    }


    public function manufacturer()
    {
        $request = Request::instance();
        $params = $request->param();  // 获取全部请求参数，保存在关联数组中

        if (empty($params['title'])) {
            $this->error('参数错误');
        }

        $title = $params['title'];
        $id = $params['id'];
        $des = isset($params['des']) ? $params['des'] : '';
        
        $companyModel = new Company();
        $productModel = new Product();
        $company = $companyModel::getById($id)->toArray();
        $query = Db::name('cms_company_product')
            ->where('company_id', $id);

        if (!empty($title)) {
            $query->where('title', $title);
        }

        if (!empty($des)) {
            $query->where('description', 'like', '%' . $des . '%');
        }

        $productData = $query->find();

        $url = 'https://www.digikey.com' . $productData['link'];
        // $url = 'http://sync.chips.selleroa.top/queryOne.php?id=' . $url;
        $url = 'https://fast.chips.selleroa.top/?id=' . $url;
        $curldata = file_get_contents($url);
        $data = json_decode($curldata, true);
        $product = $data['data'];
        $productname = $product['Manufacturer Product Number'];
        $sourceimg = $product['img'];
        $curldata2arr = $product;
        $labels = explode(">>", $product['labels']);
        $labelsurl = $product['_id'];
        $digiKeyPartNumber = $product['Digi-Key Part Number'];
        $filteredData = [
                    'Mfr' => !empty($curldata2arr['Mfr']) ? $curldata2arr['Mfr'] : '',
                    'datasheetsname1' => !empty($product['datasheetsname1']) ? $product['datasheetsname1'] : '',
                    'datasheetlink1' => !empty($product['datasheetlink1']) ? $product['datasheetlink1'] : '',
                    'rohs_status' => !empty($product['rohs_status']) ? $product['rohs_status'] : '',
                    'msl' => !empty($product['msl']) ? $product['msl'] : '',
                    'reach_status' => !empty($product['reach_status']) ? $product['reach_status'] : '',
                    'eccn' => !empty($product['eccn']) ? $product['eccn'] : '',
                    'htsus' => !empty($product['htsus']) ? $product['htsus'] : '',
                    'othername1' => !empty($product['othername1']) ? $product['othername1'] : '',
                    'standard_package' => !empty($product['standard_package']) ? $product['standard_package'] : '',
                    'stock' => !empty($product['stock']) ? $product['stock'] : '',
                    'series' => !empty($product['series']) ? $product['series'] : '',
                    'Package' => !empty($product['Package']) ? $product['Package'] : '',
                    'Product Status' => !empty($product['Product Status']) ? $product['Product Status'] : '',
                    'Container Type' => !empty($product['Container Type']) ? $product['Container Type'] : '',
                    'Size / Dimension' => !empty($product['Size / Dimension']) ? $product['Size / Dimension'] : '',
                    'Size / Dimension' => !empty($product['Size / Dimension']) ? $product['Size / Dimension'] : '',
                    'Height' => !empty($product['Height']) ? $product['Height'] : '',
                    'Area (L x W)' => !empty($product['Area (L x W)']) ? $product['Area (L x W)'] : '',
                    'Design' => !empty($product['Design']) ? $product['Design'] : '',
                    'Material' => !empty($product['Material']) ? $product['Material'] : '',
                    'Color' => !empty($product['Color']) ? $product['Color'] : '',
                    'Thickness' => !empty($product['Thickness']) ? $product['Thickness'] : '',
                    'Features' => !empty($product['Features']) ? $product['Features'] : '',
                    'Ratings' => !empty($product['Ratings']) ? $product['Ratings'] : '',
                    'Material Flammability Rating' => !empty($product['Material Flammability Rating']) ? $product['Material Flammability Rating'] : '',
                    'Shipping Info' => !empty($product['Shipping Info']) ? $product['Shipping Info'] : '',
                    'Weight' => !empty($product['Weight']) ? $product['Weight'] : '',
                    'Base Product Number' => !empty($product['Base Product Number']) ? $product['Base Product Number'] : '',
                    'tip_material' => !empty($product['tip_material']) ? $product['tip_material'] : '',
                    'handle_material' => !empty($product['handle_material']) ? $product['handle_material'] : '',
                    'rohs_status' => !empty($product['rohs_status']) ? $product['rohs_status'] : '',
                ];
        
            

        // 获取最小分类ID
        foreach ($labels as $label) {            
            $categoryId = $this->getCategoryId($labels, $labelsurl);
        }

        // 检查是否已存在相同的产品信息
        $existsproduct = Db::name('cms_product')->where('digi_key_part_number', '=', $digiKeyPartNumber)
            ->find();
        // 如果不存在相同的产品信息，则插入新数据
        $productName = $product['Manufacturer Product Number'];
        
        $productDescription = $product['Description'];
        
        $manufacturer = $product['Manufacturer'];
        $manufacturerProductNumber = $product['Manufacturer Product Number'];
        $manufacturerLeadTime = $product['Manufacturer Standard Lead Time'] ?? '';
        $detailedDescription = $product['Detailed Description'] ?? '';
        $Datasheet = $product['Datasheet'] ?? '';
        $stock = $product['stock'];
        $image = $product['img'];
        $createtime = time();
        $updatetime = time();
        $status = $product['Product Status'];
        $product_status = $product['Product Status'];
        $type = $product['Type'] ?? '';
        $material = $product['Material'] ?? '';
        $materialf = $product['Material Finish'] ?? '';
        $pnumber = $product['Base Product Number'] ?? '';
        $pmodules = $product['Product Training Modules'] ?? '';
        $rohs_status = $product['RoHS Status'] ?? '';
        $msl = $product['Moisture Sensitivity Level (MSL)'] ?? '';
        $series = $product['Series'] ?? '';
        $package = $product['Package'] ?? '';
        $eccn = $product['ECCN'] ?? '';
        $reach_status = $product['REACH Status'] ?? '';
        $htsus = $product['HTSUS'] ?? '';
        $othername1 = $product['Other Names'] ?? '';
        $standard_package = $product['Standard Package'] ?? '';
        $tool_type = $product['Tool Type'] ?? '';

        // 构建产品数据数组
        $productData = [
            'category_id' => $categoryId,
            'name' => $productName,
            'url' => $url,
            'description' => $productDescription,
            'digi_key_part_number' => $digiKeyPartNumber,
            'manufacturer' => $manufacturer,
            'manufacturer_product_number' => $manufacturerProductNumber,
            'manufacturer_lead_time' => $manufacturerLeadTime,
            'detailed_description' => $detailedDescription,
            'datasheet' => $Datasheet,
            'customer_reference' => '',
            'datasheetlink1' => $Datasheet,
            'image' => $image,
            'createtime' => $createtime,
            'updatetime' => $updatetime,
            'status' => $status,
            'rohs_status' => $rohs_status,
            'msl' => $msl,
            'reach_status' => $reach_status,
            'eccn' => $eccn,
            'htsus' => $htsus,
            'othername1' => $othername1,
            'standard_package' => $standard_package,
            'stock' => $stock,
            'series' => $series,
            'package' => $package,
            'product_status' => $product_status,
            'type' => $type,
            'tool_type' => $tool_type,
        ];


        if (!$existsproduct) {

            $productModel::insert($productData);
            $productId = $productModel::getLastInsID();
        } else {
            $productId = $existsproduct['id'];

            $productModel::where('id', $productId)  // 假设$productId是要更新的产品ID
                ->update($productData);
        }

        // 插入产品关联表
        $products = [];

        for ($i = 1; $i <= 8; $i++) {
            $qtyKey = "QTY" . $i;
            if (isset($params[$qtyKey])) {
                $qtyData = explode(" ", $params[$qtyKey]);
                if (count($qtyData) === 3) {
                    $qty = (int)$qtyData[0];
                    $unitPrice = $qtyData[1];
                    $extPrice = $qtyData[2];

                    // 检查是否已存在相同的产品关联数据
                    $relation = Db::name('product_relation')
                        ->where('product_id', '=', $productId)
                        ->where('qty', '=', $qty)
                        ->where('unit_price', '=', $unitPrice)
                        ->find();

                    if (!$relation) {
                        // 如果不存在相同的关联数据，则插入新数据
                        $relationData = [
                            'product_id' => $productId,
                            'qty' => $qty,
                            'unit_price' => $unitPrice,
                            'ext_price' => $extPrice,
                        ];
                        $products[] = $relationData;
                    }
                }
            }
        }

        if (!empty($products)) {
            Db::name('product_relation')->insertAll($products);
        }
        $product = Db::name('cms_product')->where('name', $productname)->find();
        $product = $product = Product::getinfo($product['id']);

        // 提取image字段并替换域名
        $product['image'] = str_replace('//media.digikey.com', 'https://image.chips.selleroa.top', $product['image']);
        $product['image'] = str_replace('https:https://', 'https://', $product['image']);
        $product['datasheet'] = str_replace('https:http://', 'https://', $product['datasheet']);
        $original_url = $product['image'];
        $base_directory = '/home/imgs/img_fold';
        $imageUrl = $product['image'];
        // 替换域名
        $relative_path = str_replace('//media.digikey.com', $base_directory, $original_url);

        // 将相对路径转换为绝对路径
        $absolute_path = realpath(($relative_path));
        if (empty($absolute_path)) {
            // 提取image字段并替换域名
//            $product['image'] = str_replace('https://mm.digikey.com', 'https://image.chips.selleroa.top', $product['image']);
            $product['image'] = str_replace('https:https://', 'https://', $product['image']);
            $product['datasheet'] = str_replace('https:http://', 'https://', $product['datasheet']);
            $original_url = $product['image'];
            $base_directory = '/home/imgs/img_fold';

            // 替换域名
            $relative_path = str_replace('https://mm.digikey.com', $base_directory, $original_url);

            // 将相对路径转换为绝对路径
            $absolute_path = realpath(($relative_path));
        }

        if ($relative_path !== false && file_exists($relative_path) && strpos($product['image'], 'digikey.com') !== false) {

             // echo $absolute_path . "文件存在：$absolute_path" . 'relative_path-' . $relative_path . '-absolute_path-' . $absolute_path;
             if (strpos($imageUrl, 'mm.digikey.com') !== false) {
                $imageUrl = str_replace('https://mm.digikey.com/', 'https://image.chips.selleroa.top/', $imageUrl);
            } else {
                $imageUrl = str_replace('//media.digikey.com', 'https://image.chips.selleroa.top', $imageUrl);
            }

            $product['image'] = $imageUrl;
            if (isset($product['img'])) {
                $product['img'] = str_replace('https://mm.digikey.com', 'https://image.chips.selleroa.top', $product['img']);
            }
        } else {
             // echo "文件不存在：$absolute_path" . 'relative_path-' . $relative_path . '-absolute_path-' . $absolute_path;

            // 假设已经建立了 Redis 连接
            if ($sourceimg != 'https://media.digikey.com/photos/nophoto/pna_en.jpg') {
                $jsonarr = ["_id" => $product['url'], 'img' => $sourceimg];

                if (strpos($imageUrl, 'mm.digikey.com') !== false) {
                    $imageUrl = str_replace('https://mm.digikey.com/', 'https://image.chips.selleroa.top/', $imageUrl);
                } else {
                    $imageUrl = str_replace('//media.digikey.com', 'https://image.chips.selleroa.top', $imageUrl);
                }

                $product['image'] = $imageUrl;
                if (isset($product['img'])) {
                    $product['img'] = str_replace('https://mm.digikey.com', 'https://image.chips.selleroa.top', $product['img']);
                }
                $redis = new Redis();
                $redis->connect('127.0.0.1', 6379);
                $redis->auth('zb07dvq8d4Yf3SKz4Vlai3Nq');  // 在这里设置 Redis 密码
                $redisKey = 'digi_img_url';
                // 删除之前存储的键值
                $redis->del('digi_img_url');
                // 将数据插入 Redis 列表
                $result = $redis->lPush('digi_img_url', json_encode($jsonarr));
                // 读取 Redis 列表中的所有元素
                $list = $redis->lRange('digi_img_url', 0, -1);

                // 设置缓存过期时间，假设为1小时
                $redis->expire($redisKey, 3600 * 24 * 7);
            } else {
                $product['image'] = '/static/picture/wd.png';
            }

        }
        if (isset($product['othername1'])) {
            $othername1 = $product['othername1'];
        } else {
            $othername = $product['Other Names'];
            $othername1 = $product['Other Names'];
        }
        $othername = $othername1;
        $othernamearr = explode('&', $othername);

        //获取所属分类
        $categories = $this->getCategoryNamesWithId($categoryId);
        $ProductRelation = new ProductRelations();
        $unitPrice = (float)$ProductRelation->where('id', $id)->where('qty', 1)->value('unit_price');
        $extPrice = (float)$ProductRelation->where('id', $id)->where('qty', 1)->value('ext_price');
        $extPrice = number_format($extPrice, 2);
        
        $this->assign('categories', $categories);
        $this->assign('product', $product);
        $this->assign('othername', $othernamearr);
        $this->assign('filteredData', $filteredData);
        $this->assign('unitPrice', $unitPrice);
        $this->assign('extPrice', $extPrice);

        return $this->view->fetch('productdetail');
    }

    function generateFileName($prefix = '') {
        // 生成一个唯一的文件名
        $filename = uniqid($prefix, true); // 在前缀后面加上32位的唯一字符串
        $filename = substr($filename, 0, 10); // 截取前10位
        return $filename;
    }

    function downloadImage($url, $savePath)
    {
        $ch = curl_init($url);
        $fp = fopen($savePath, 'wb');
        
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        
        curl_exec($ch);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        fclose($fp);
        var_dump($httpCode);exit;
        if ($httpCode !== 200) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 根据标签列表获取最小分类ID
     *
     * @param array $labels 商品标签列表
     * @param string $url 分类链接
     * @return int 分类ID
     */
    function getCategoryId($labels, $url)
    {
        $categoryId = 0;

        // 遍历标签列表，获取最小分类ID
        foreach ($labels as $label) {
            $category = Db::name('product_category')->where('name', $label)->where('pid', $categoryId)->find();
            if (empty($category)) {
                // 如果分类不存在，则创建新的分类
                $categoryId = Db::name('product_category')->insertGetId([
                    'name' => $label,
                    'pid' => $categoryId,
                    'url' => '',
                ]);
            } else {
                $categoryId = $category['id'];
            }
        }

        // 更新最小分类的链接
        if ($categoryId > 0) {
            Db::name('product_category')->where('id', $categoryId)->update(['url' => $url]);
        }

        return $categoryId;
    }


    /**
     * 递归创建分类树
     *
     * @param array $labels 分类名称数组
     * @param string $url 分类链接
     * @param int $pid 父级分类ID
     * @return int 最小分类ID
     */
    function createCategoryTree($labels, $url, $pid = 0)
    {
        // 如果标签数组为空，则返回父级分类ID
        if (empty($labels)) {
            return $pid;
        }

        // 获取当前标签
        $label = array_shift($labels);

        // 获取当前分类的ID
        $categoryId = $this->getCategoryId($label, $pid, $url);

        // 递归创建子级分类
        $childCategoryId = $this->createCategoryTree($labels, $url, $categoryId);

        // 返回子级分类ID
        return $childCategoryId;
    }

    /**
     * 根据分类ID获取最顶级分类名和所有上级分类名称及对应的分类ID
     *
     * @param int $categoryId 分类ID
     * @return array 分类名称和对应分类ID的关联数组，包括最顶级分类及其对应ID和所有上级分类及其对应ID
     */
    function getCategoryNamesWithId($categoryId)
    {
        $categoryData = [];

        // 查询给定分类ID的所有上级分类
        $categories = Db::name('product_category')->field('id, name, pid')->select();

        // 构建分类ID到分类数据的映射关系数组
        $categoryMap = [];
        foreach ($categories as $category) {
            $categoryMap[$category['id']] = $category;
        }        

        // 调用递归函数获取最顶级分类名和所有上级分类名称及对应的分类ID
        $this->fetchCategoryNamesWithId($categoryId, $categoryMap, $categoryData);

        return $categoryData;
    }

    // 递归获取最顶级分类名和所有上级分类名称及对应的分类ID
    function fetchCategoryNamesWithId($categoryId, &$categoryMap, &$categoryData)
    {
        if (isset($categoryMap[$categoryId])) {
            $category = $categoryMap[$categoryId];
            $categoryData[$category['name']] = $categoryId;

            if ($category['pid'] > 0) {
                $this->fetchCategoryNamesWithId($category['pid'], $categoryMap, $categoryData);
            }
        }
    }

    public function getById() 
    {
        $id = 'https://www.digikey.com/en/products/detail/arc-suppression-technologies/GCKAC3T480/19235669';
        $data = $this->queryById($id);
    }

    private function queryProductInfoById($id) 
    {
        $url = 'https://sync.chips.selleroa.top/queryOne.php?id=' . $id;
        $data = file_get_contents($url);
        return $data;
    }

}
