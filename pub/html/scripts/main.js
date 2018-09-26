// requirejs([], function() {
//     alert("Hello World");
// });

requirejs(['helper/world'], function(helper_world) {
    var message = helper_world.getMessage();
    alert(message);
});