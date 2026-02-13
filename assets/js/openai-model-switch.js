document.addEventListener('DOMContentLoaded', function() {
    var modelSelect = document.getElementById('hhgfotr-openai-model');
    var customModelContainer = document.getElementById('hhgfotr-openai-custom-model-container');
    if(modelSelect && customModelContainer){
        modelSelect.addEventListener('change', function(){
            if(this.value === 'custom'){
                customModelContainer.style.display = '';
            }else{
                customModelContainer.style.display = 'none';
            }
        });
    }
});