<?php


#[Controller('/v1/dummy')]
class DummyController
{
    // Ping
    #[Get('/ping')]
    public function ping(Request $request)
    {
        return new DataSuccess('pong', ['message' => 'pong']);
    }

    // Echo
    #[Post('/echo')]
    public function echo(Request $request)
    {
        $data = $request->body->all();
        return new DataSuccess('echo', ['echo' => $data]);
    }

    // Status
    #[Get('/status')]
    public function status(Request $request)
    {
        return new DataSuccess('status', ['status' => 'ok']);
    }

    // Create item
    #[Post('/create-item')]
    public function createItem(Request $request)
    {
        $payload = $request->body->all();
        return new DataSuccess('create', ['created' => $payload]);
    }

    // List items
    #[Get('/list-items')]
    public function listItems(Request $request)
    {
        $params = $request->query->all();
        return new DataSuccess('list', ['items' => [], 'filters' => $params]);
    }

    // Update item
    #[Post('/update-item')]
    public function updateItem(Request $request)
    {
        $id = $request->body->getString('id');
        $data = $request->body->all();
        return new DataSuccess('update', ['id' => $id, 'updated' => $data]);
    }

    // Delete item
    #[Delete('/delete-item')]
    public function deleteItem(Request $request)
    {
        $id = $request->query->getString('id');
        return new DataSuccess('delete', ['deletedId' => $id]);
    }

    // Get item by id
    #[Get('/items/{id}')]
    public function getItem(Request $request)
    {
        $id = $request->params->getString('id');
        return new DataSuccess('item', ['id' => $id, 'data' => []]);
    }

    // Upload
    #[Post('/upload')]
    public function upload(Request $request)
    {
        $file = $request->files->getFile('file');
        $fileName = $file ? $file->getClientFilename() : null;
        return new DataSuccess('upload', ['fileName' => $fileName]);
    }

    // Search
    #[Get('/search')]
    public function search(Request $request)
    {
        $q = $request->query->getString('q');
        return new DataSuccess('search', ['query' => $q, 'results' => []]);
    }

    // Login
    #[Post('/login')]
    public function login(Request $request)
    {
        $creds = $request->body->all();
        return new DataSuccess('login', ['token' => 'dummy-token', 'user' => $creds]);
    }

    // Logout
    #[Get('/logout')]
    public function logout(Request $request)
    {
        return new DataSuccess('logout', ['message' => 'logged out']);
    }

    // Health
    #[Get('/health')]
    public function health(Request $request)
    {
        return new DataSuccess('health', ['status' => 'healthy']);
    }

    // Info
    #[Get('/info')]
    public function info(Request $request)
    {
        return new DataSuccess('info', ['framework' => 'ApiPro', 'version' => '2.4.1']);
    }

    // Version
    #[Get('/version')]
    public function version(Request $request)
    {
        return new DataSuccess('version', ['version' => '2.4.1']);
    }

    // Extra endpoints 1-12
    #[Get('/extra1')]
    public function extra1(Request $request) { return new DataSuccess('extra1', ['detail' => 'extra endpoint 1']); }
    #[Get('/extra2')]
    public function extra2(Request $request) { return new DataSuccess('extra2', ['detail' => 'extra endpoint 2']); }
    #[Get('/extra3')]
    public function extra3(Request $request) { return new DataSuccess('extra3', ['detail' => 'extra endpoint 3']); }
    #[Get('/extra4')]
    public function extra4(Request $request) { return new DataSuccess('extra4', ['detail' => 'extra endpoint 4']); }
    #[Get('/extra5')]
    public function extra5(Request $request) { return new DataSuccess('extra5', ['detail' => 'extra endpoint 5']); }
    #[Get('/extra6')]
    public function extra6(Request $request) { return new DataSuccess('extra6', ['detail' => 'extra endpoint 6']); }
    #[Get('/extra7')]
    public function extra7(Request $request) { return new DataSuccess('extra7', ['detail' => 'extra endpoint 7']); }
    #[Get('/extra8')]
    public function extra8(Request $request) { return new DataSuccess('extra8', ['detail' => 'extra endpoint 8']); }
    #[Get('/extra9')]
    public function extra9(Request $request) { return new DataSuccess('extra9', ['detail' => 'extra endpoint 9']); }
    #[Get('/extra10')]
    public function extra10(Request $request) { return new DataSuccess('extra10', ['detail' => 'extra endpoint 10']); }
    #[Get('/extra11')]
    public function extra11(Request $request) { return new DataSuccess('extra11', ['detail' => 'extra endpoint 11']); }
    #[Get('/extra12')]
    public function extra12(Request $request) { return new DataSuccess('extra12', ['detail' => 'extra endpoint 12']); }
}
?>
